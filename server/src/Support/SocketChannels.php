<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who may subscribe to FleetOps realtime channels.
 *
 * Channel names are "{prefix}.{id}" where the prefix is the snake_case model name the lifecycle
 * events broadcast on (plus `driverAssigned`, which the order's assigned-driver channel uses) and
 * the id is the model's uuid or public_id.
 *
 * Console users and API credentials may subscribe to any of their own company's records. Drivers
 * and customers get narrow access only:
 *
 * - a driver: their own driver record, their current vehicle and its devices and positions, and
 *   orders assigned to them (directly, or through one of the order's payload entities);
 * - a customer: orders they are the customer of, and the driver and vehicle of those orders while
 *   they are active.
 *
 * Public tracking channels (`tracking.{opaque}`) are denied here to everyone: only a `tracking`
 * token, whose scope names the channel exactly, can subscribe to one.
 */
class SocketChannels
{
    /**
     * Order statuses after which a customer no longer sees the order's driver or vehicle.
     */
    public const TERMINAL_ORDER_STATUSES = ['completed', 'canceled'];

    /**
     * Prefixes resolved by the generic model resolver, with their model.
     */
    public const MODEL_PREFIXES = [
        'order'           => Order::class,
        'driver'          => Driver::class,
        'driverAssigned'  => Driver::class,
        'vehicle'         => Vehicle::class,
        'trailer'         => Trailer::class,
        'device'          => Device::class,
        'waypoint'        => Waypoint::class,
        'entity'          => Entity::class,
        'place'           => Place::class,
        'contact'         => Contact::class,
        'fleet'           => Fleet::class,
        'zone'            => Zone::class,
        'service_area'    => ServiceArea::class,
        'service_rate'    => ServiceRate::class,
        'service_quote'   => ServiceQuote::class,
        'purchase_rate'   => PurchaseRate::class,
        'tracking_status' => TrackingStatus::class,
        'tracking_number' => TrackingNumber::class,
        'payload'         => Payload::class,
    ];

    /**
     * Prefixes whose id may name more than one kind of record, with the models tried in order.
     */
    public const MORPH_PREFIXES = [
        'customer'    => [Contact::class, Vendor::class],
        'vendor'      => [Vendor::class, IntegratedVendor::class],
        'facilitator' => [Vendor::class, IntegratedVendor::class, Contact::class],
    ];

    /**
     * Register every FleetOps prefix and the driver principal resolver.
     */
    public static function register(SocketChannelRegistry $registry): void
    {
        foreach (self::MODEL_PREFIXES as $prefix => $modelClass) {
            $registry->registerModel($prefix, $modelClass, static::narrowFor($prefix));
        }

        foreach (self::MORPH_PREFIXES as $prefix => $modelClasses) {
            $registry->register($prefix, static::resolver($modelClasses, [static::class, 'narrowSelf']));
        }

        // Positions have no public_id, so they are looked up by uuid only.
        $registry->register('position', static::resolver([Position::class], [static::class, 'narrowPosition'], false));

        // tracking.{opaque} is reachable only through a tracking token's exact scope.
        $registry->register(TrackingChannel::PREFIX, static fn (): bool => false);

        $registry->registerPrincipalResolver([static::class, 'driverPrincipal']);
    }

    /**
     * The narrow rule used for driver and customer principals on a generic model prefix.
     */
    public static function narrowFor(string $prefix): ?callable
    {
        return match ($prefix) {
            'order'                    => [static::class, 'narrowOrder'],
            'driver', 'driverAssigned' => [static::class, 'narrowDriver'],
            'vehicle'                  => [static::class, 'narrowVehicle'],
            'device'                   => [static::class, 'narrowDevice'],
            'contact'                  => [static::class, 'narrowSelf'],
            default                    => null,
        };
    }

    /**
     * A resolver over one or more models: company match for user and API principals, the narrow
     * rule for driver and customer principals, deny for everyone else.
     *
     * @param array<int, class-string<Model>> $modelClasses
     */
    public static function resolver(array $modelClasses, ?callable $narrow = null, bool $byPublicId = true): \Closure
    {
        return static function (SocketPrincipal $principal, string $id, string $channel = '') use ($modelClasses, $narrow, $byPublicId): bool {
            $narrowed = $principal->kind === 'driver' || $principal->kind === 'customer';
            if ((!$principal->isCompanyScoped() && !$narrowed) || $id === '') {
                return false;
            }

            $model = static::find($modelClasses, $principal, $id, $byPublicId);
            if ($model === null) {
                return false;
            }

            if ($principal->isCompanyScoped()) {
                return $principal->cid !== null && $model->company_uuid === $principal->cid;
            }

            return $narrow !== null && (bool) $narrow($principal, $model);
        };
    }

    /**
     * The first of the models whose uuid (or public_id) is the id, in the principal's environment.
     *
     * @param array<int, class-string<Model>> $modelClasses
     */
    public static function find(array $modelClasses, SocketPrincipal $principal, string $id, bool $byPublicId = true): ?Model
    {
        foreach ($modelClasses as $modelClass) {
            $model = $modelClass::on(static::connection($principal))
                ->where(function ($query) use ($id, $byPublicId) {
                    $query->where('uuid', $id);
                    if ($byPublicId) {
                        $query->orWhere('public_id', $id);
                    }
                })
                ->first();

            if ($model) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The database connection for the principal's environment; null is the default one.
     */
    public static function connection(SocketPrincipal $principal): ?string
    {
        return $principal->env === 'test' ? 'sandbox' : null;
    }

    /**
     * Orders: a driver's when assigned to them (directly or on one of its payload entities), a customer's own.
     */
    public static function narrowOrder(SocketPrincipal $principal, Model $order): bool
    {
        if ($principal->kind === 'customer') {
            return static::isSelf($principal, $order->customer_uuid);
        }

        if ($principal->kind !== 'driver') {
            return false;
        }

        if (static::isSelf($principal, $order->driver_assigned_uuid)) {
            return true;
        }

        return !empty($order->payload_uuid)
            && Entity::on($order->getConnectionName())
                ->where('payload_uuid', $order->payload_uuid)
                ->where('driver_assigned_uuid', $principal->sub)
                ->exists();
    }

    /**
     * Drivers: a driver only themselves; a customer the driver of one of their active orders.
     */
    public static function narrowDriver(SocketPrincipal $principal, Model $driver): bool
    {
        if ($principal->kind === 'driver') {
            return static::isSelf($principal, $driver->uuid);
        }

        return $principal->kind === 'customer'
            && static::activeCustomerOrders($principal, $driver->getConnectionName())
                ->where('driver_assigned_uuid', $driver->uuid)
                ->exists();
    }

    /**
     * Vehicles: a driver their current vehicle; a customer the vehicle of one of their active orders,
     * whether assigned to the order or driven by the order's driver.
     */
    public static function narrowVehicle(SocketPrincipal $principal, Model $vehicle): bool
    {
        if ($principal->kind === 'driver') {
            return static::driverVehicleUuid($principal, $vehicle->getConnectionName()) === $vehicle->uuid;
        }

        if ($principal->kind !== 'customer') {
            return false;
        }

        $connection = $vehicle->getConnectionName();
        $drivers    = Driver::on($connection)->where('vehicle_uuid', $vehicle->uuid)->select('uuid')->toBase();

        return static::activeCustomerOrders($principal, $connection)
            ->where(function ($query) use ($vehicle, $drivers) {
                $query->where('vehicle_assigned_uuid', $vehicle->uuid)->orWhereIn('driver_assigned_uuid', $drivers);
            })
            ->exists();
    }

    /**
     * Devices: a driver those attached to themselves or to their current vehicle.
     */
    public static function narrowDevice(SocketPrincipal $principal, Model $device): bool
    {
        return $principal->kind === 'driver' && static::ownsDriverSubject($principal, $device->attachable_uuid, $device->getConnectionName());
    }

    /**
     * Positions: a driver those recorded for themselves or for their current vehicle.
     */
    public static function narrowPosition(SocketPrincipal $principal, Model $position): bool
    {
        return $principal->kind === 'driver' && static::ownsDriverSubject($principal, $position->subject_uuid, $position->getConnectionName());
    }

    /**
     * Contacts, customers, vendors, facilitators: a customer only themselves.
     */
    public static function narrowSelf(SocketPrincipal $principal, Model $model): bool
    {
        return $principal->kind === 'customer' && static::isSelf($principal, $model->uuid);
    }

    /**
     * Claim a Sanctum-authenticated user who is a driver of the current company as a driver principal.
     */
    public static function driverPrincipal(Request $request, $user): ?SocketPrincipal
    {
        if (!$user instanceof User || !$user->uuid) {
            return null;
        }

        $companyUuid = session('company') ?: $user->company_uuid;
        if (!$companyUuid) {
            return null;
        }

        $driver = Driver::where('user_uuid', $user->uuid)->where('company_uuid', $companyUuid)->first();
        if (!$driver) {
            return null;
        }

        $connection = $driver->getConnectionName();
        $cpid       = Company::on($connection)->where('uuid', $companyUuid)->value('public_id');

        return new SocketPrincipal(
            kind: 'driver',
            sub: (string) $driver->uuid,
            cid: (string) $companyUuid,
            cpid: is_string($cpid) && $cpid !== '' ? $cpid : null,
            env: $connection === 'sandbox' ? 'test' : 'live',
            ids: array_values(array_filter([$driver->uuid, $driver->public_id, $user->uuid, $user->public_id], fn ($id) => is_string($id) && $id !== '')),
            adm: false,
        );
    }

    /**
     * The customer's orders that are not completed or canceled.
     */
    protected static function activeCustomerOrders(SocketPrincipal $principal, ?string $connection): Builder
    {
        return Order::on($connection)
            ->whereIn('customer_uuid', array_values(array_unique([$principal->sub, ...$principal->ids])))
            ->whereNotIn('status', self::TERMINAL_ORDER_STATUSES);
    }

    /**
     * Whether a record's subject is the driver principal or the driver's current vehicle.
     */
    protected static function ownsDriverSubject(SocketPrincipal $principal, ?string $subjectUuid, ?string $connection): bool
    {
        if (!$subjectUuid) {
            return false;
        }

        return static::isSelf($principal, $subjectUuid) || static::driverVehicleUuid($principal, $connection) === $subjectUuid;
    }

    /**
     * Whether an id is the principal's own (its subject or one of its ids).
     */
    protected static function isSelf(SocketPrincipal $principal, mixed $id): bool
    {
        return is_string($id) && $id !== '' && ($id === $principal->sub || $principal->owns($id));
    }

    protected static function driverVehicleUuid(SocketPrincipal $principal, ?string $connection): ?string
    {
        $vehicleUuid = Driver::on($connection)->where('uuid', $principal->sub)->value('vehicle_uuid');

        return is_string($vehicleUuid) && $vehicleUuid !== '' ? $vehicleUuid : null;
    }
}
