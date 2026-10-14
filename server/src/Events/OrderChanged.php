<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Notifications\OrderChanged as OrderChangedNotification;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Broadcast as `order.changed` when an order's route, items, contact, schedule,
 * notes or amounts were edited after it was placed.
 *
 * Channels: order.{public_id}, order.{uuid}, driver.{public_id} and driver.{uuid}
 * when a driver is assigned, customer.{public_id} when a customer exists and
 * company.{company_uuid}.
 *
 * Data: { id, changes[], summary, dropoff, cod_amount, total, currency, changed_by, changed_at }.
 *
 * Every value is captured when the event is created, so the broadcast does not
 * depend on the order still looking the same when the queue sends it.
 */
class OrderChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * Changes that concern the assigned driver: these send them a push.
     */
    public const DRIVER_CHANGES = ['pickup', 'dropoff', 'return', 'waypoints', 'items', 'cod_amount', 'schedule', 'notes'];

    public string $eventId;

    public string $sentAt;

    public array $channelNames = [];

    public array $data = [];

    /**
     * @param array  $changes the changed parts: dropoff, items, contact, schedule, notes, total, cod_amount, ...
     * @param string $summary one human line describing the change
     * @param array  $extra   overrides for the data: total, currency, cod_amount, changed_by, changed_at, dropoff
     */
    public function __construct(Order $order, array $changes, string $summary = '', array $extra = [])
    {
        $this->eventId      = uniqid('event_');
        $this->sentAt       = Carbon::now()->toDateTimeString();
        $this->channelNames = static::channelNamesFor($order);
        $this->data         = static::dataFor($order, $changes, $summary, $extra);
    }

    /**
     * Broadcast `order.changed` for the order and, unless told otherwise, push the
     * change to the assigned driver when it concerns them.
     *
     * Storefront and other extensions call this after their own order edits.
     */
    public static function dispatchFor(Order $order, array $changes, string $summary = '', array $extra = [], bool $notifyDriver = true): ?self
    {
        $changes = array_values(array_unique(array_filter($changes, 'is_string')));
        if ($changes === []) {
            return null;
        }

        $event = new static($order, $changes, $summary, $extra);

        try {
            event($event);
        } catch (\Throwable $exception) {
            Log::warning('Unable to broadcast order.changed', ['order' => $order->public_id, 'error' => $exception->getMessage()]);
        }

        if ($notifyDriver && static::concernsDriver($changes)) {
            static::notifyDriver($order, $event);
        }

        return $event;
    }

    /**
     * Whether any of the changes concerns the driver doing the order.
     */
    public static function concernsDriver(array $changes): bool
    {
        return (bool) array_intersect($changes, self::DRIVER_CHANGES);
    }

    /**
     * Push the change to the order's assigned driver.
     */
    public static function notifyDriver(Order $order, self $event): bool
    {
        if (!$order->driver_assigned_uuid) {
            return false;
        }

        try {
            $driver = static::assignedDriver($order);
            if (!$driver) {
                return false;
            }

            $driver->notify(new OrderChangedNotification($order, $event->data['changes'], (string) $event->data['summary']));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Unable to notify driver of order change', ['order' => $order->public_id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * The order's assigned driver, read past the driver scope as the order listeners do.
     */
    public static function assignedDriver(Order $order): ?Driver
    {
        if (!$order->driver_assigned_uuid) {
            return null;
        }

        $driver = $order->relationLoaded('driverAssigned') ? $order->getRelation('driverAssigned') : null;
        if ($driver instanceof Driver && $driver->uuid === $order->driver_assigned_uuid) {
            return $driver;
        }

        $driver = Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();
        if ($driver) {
            $order->setRelation('driverAssigned', $driver);
        }

        return $driver;
    }

    public static function channelNamesFor(Order $order): array
    {
        $channels = [
            'order.' . $order->public_id,
            'order.' . $order->uuid,
        ];

        if ($order->driver_assigned_uuid) {
            $driverPublicId = static::attempt(fn () => static::assignedDriver($order)?->public_id);
            if ($driverPublicId) {
                $channels[] = 'driver.' . $driverPublicId;
            }
            $channels[] = 'driver.' . $order->driver_assigned_uuid;
        }

        if ($order->customer_uuid) {
            $customerPublicId = data_get($order, 'customer.public_id');
            if ($customerPublicId) {
                $channels[] = 'customer.' . $customerPublicId;
            }
        }

        if ($order->company_uuid) {
            $channels[] = 'company.' . $order->company_uuid;
        }

        return array_values(array_unique($channels));
    }

    public static function dataFor(Order $order, array $changes, string $summary = '', array $extra = []): array
    {
        $payload = $order->relationLoaded('payload') ? $order->payload : static::attempt(fn () => $order->payload);

        return [
            'id'         => $order->public_id,
            'changes'    => array_values($changes),
            'summary'    => $summary,
            'dropoff'    => array_key_exists('dropoff', $extra) ? $extra['dropoff'] : static::placeData($payload?->dropoff ?? null),
            'cod_amount' => array_key_exists('cod_amount', $extra) ? $extra['cod_amount'] : static::numberOrNull($payload?->cod_amount ?? null),
            'total'      => array_key_exists('total', $extra) ? $extra['total'] : static::numberOrNull(static::attempt(fn () => $order->transaction_amount)),
            'currency'   => array_key_exists('currency', $extra) ? $extra['currency'] : (static::attempt(fn () => $order->transaction_currency) ?? $payload?->cod_currency ?? null),
            'changed_by' => array_key_exists('changed_by', $extra) ? $extra['changed_by'] : static::currentUserName(),
            'changed_at' => $extra['changed_at'] ?? Carbon::now()->toIso8601ZuluString(),
        ];
    }

    /**
     * The drop-off as `{ id, address, location: { type: Point, coordinates: [lng, lat] } }`.
     */
    public static function placeData(?Place $place): ?array
    {
        if (!$place instanceof Place) {
            return null;
        }

        $location = $place->location;

        return [
            'id'       => $place->public_id,
            'address'  => static::attempt(fn () => $place->address),
            'location' => $location instanceof Point ? ['type' => 'Point', 'coordinates' => [$location->getLng(), $location->getLat()]] : null,
        ];
    }

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new Channel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->eventId,
            'api_version' => config('api.version'),
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => $this->data,
        ];
    }

    protected static function currentUserName(): ?string
    {
        $userUuid = session('user');
        if (!$userUuid) {
            return null;
        }

        return static::attempt(fn () => User::where('uuid', $userUuid)->value('name'));
    }

    protected static function numberOrNull(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }

    protected static function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return null;
        }
    }
}
