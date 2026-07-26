<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\Route;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsOrderUnitFake extends Order
{
    public bool $saved              = false;
    public array $loaded            = [];
    public array $loadedMissing     = [];
    public array $quietUpdates      = [];
    public array $filled            = [];
    public array $activityRows      = [];
    public array $statuses          = [];
    public ?OrderConfig $fakeConfig = null;
    public mixed $customValue       = null;
    public mixed $metaValue         = null;
    public bool $customFieldExists  = false;
    public bool $metaExists         = false;

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function fill(array $attributes)
    {
        $this->filled[] = $attributes;

        return parent::fill($attributes);
    }

    public function config(): ?OrderConfig
    {
        $this->fakeConfig?->setOrderContext($this);

        return $this->fakeConfig;
    }

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activityRows[] = [$activity->code, $location, $proof];

        return 'tracking_status_public';
    }

    public function setStatus(?string $status, $andSave = true)
    {
        $this->statuses[] = [$status, $andSave];
        $this->status     = $status;

        return $this;
    }

    public function isCustomField(string $key): bool
    {
        return $this->customFieldExists && $key === 'doorCode';
    }

    public function getCustomFieldValueByKey(string $key, mixed $default = null): mixed
    {
        return $this->customValue ?? $default;
    }

    public function hasMeta($keys): bool
    {
        return $this->metaExists && $keys === 'priority_note';
    }

    public function getMeta($key = null, $defaultValue = null)
    {
        return $this->metaValue ?? $defaultValue;
    }
}

class FleetOpsOrderUnitProbe extends FleetOpsOrderUnitFake
{
    public function shouldResolvePayloadPlaceForTest(?array $attributes, string $role): bool
    {
        return $this->shouldResolvePayloadPlace($attributes, $role);
    }

    public function hasExistingPayloadPlaceUuidForTest(?array $attributes, string $role): bool
    {
        return $this->hasExistingPayloadPlaceUuid($attributes, $role);
    }

    public function getPickupLocation()
    {
        return null;
    }
}

class FleetOpsOrderUnitConfigFake extends OrderConfig
{
    public ?Order $context = null;
    public array $flow     = [];

    public function setOrderContext(Order $order): self
    {
        $this->context = $order;

        return $this;
    }
}

class FleetOpsOrderUnitWaypointFake extends Waypoint
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

function fleetopsOrderUnitUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsOrderUnitPlace(string $uuid, Point $location): Place
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => $uuid], true);
    $place->location = $location;

    return $place;
}

test('order relationship contracts resolve expected relation types and models', function () {
    fleetopsOrderUnitUseRelationConnection();
    Order::boot();

    $order = new Order();

    expect($order->orderConfig())->toBeInstanceOf(BelongsTo::class)
        ->and($order->orderConfig()->getRelated())->toBeInstanceOf(OrderConfig::class)
        ->and($order->transaction())->toBeInstanceOf(BelongsTo::class)
        ->and($order->transaction()->getRelated())->toBeInstanceOf(Transaction::class)
        ->and($order->route())->toBeInstanceOf(BelongsTo::class)
        ->and($order->route()->getRelated())->toBeInstanceOf(Route::class)
        ->and($order->payload())->toBeInstanceOf(BelongsTo::class)
        ->and($order->payload()->getRelated())->toBeInstanceOf(Payload::class)
        ->and($order->company())->toBeInstanceOf(BelongsTo::class)
        ->and($order->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($order->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($order->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($order->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($order->updatedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($order->driverAssigned())->toBeInstanceOf(BelongsTo::class)
        ->and($order->driverAssigned()->getRelated())->toBeInstanceOf(Driver::class)
        ->and($order->driver())->toBeInstanceOf(BelongsTo::class)
        ->and($order->driver()->getRelated())->toBeInstanceOf(Driver::class)
        ->and($order->vehicleAssigned())->toBeInstanceOf(BelongsTo::class)
        ->and($order->vehicleAssigned()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($order->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($order->vehicle()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($order->comments())->toBeInstanceOf(HasMany::class)
        ->and($order->files())->toBeInstanceOf(HasMany::class)
        ->and($order->drivers())->toBeInstanceOf(HasManyThrough::class)
        ->and($order->trackingNumber())->toBeInstanceOf(BelongsTo::class)
        ->and($order->trackingNumber()->getRelated())->toBeInstanceOf(TrackingNumber::class)
        ->and($order->trackingStatuses())->toBeInstanceOf(HasMany::class)
        ->and($order->trackingStatuses()->getRelated())->toBeInstanceOf(TrackingStatus::class)
        ->and($order->proofs())->toBeInstanceOf(HasMany::class)
        ->and($order->proofs()->getRelated())->toBeInstanceOf(Proof::class)
        ->and($order->purchaseRate())->toBeInstanceOf(BelongsTo::class)
        ->and($order->purchaseRate()->getRelated())->toBeInstanceOf(PurchaseRate::class)
        ->and($order->facilitator())->toBeInstanceOf(MorphTo::class)
        ->and($order->customer())->toBeInstanceOf(MorphTo::class)
        ->and($order->authenticatableCustomer())->toBeInstanceOf(BelongsTo::class)
        ->and($order->authenticatableCustomer()->getRelated())->toBeInstanceOf(Contact::class);
});

test('order mutators helper probes and loaded payload callbacks use local state', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 11:00:00'));

    $order = new FleetOpsOrderUnitProbe();
    $order->setRawAttributes(['created_at' => '2026-07-26 08:00:00'], true);

    $order->orchestrator_priority = null;
    $order->type                  = 'Express Service';
    $order->status                = 'Driver Assigned';
    $order->time_window_start     = '09:15:00';
    $order->time_window_end       = '';

    $scheduled = new FleetOpsOrderUnitProbe();
    $scheduled->setRawAttributes(['scheduled_at' => '2026-08-03 13:00:00'], true);
    $scheduled->time_window_start = '10:30:00';

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);
    $order->setRelation('payload', $payload);

    $callbackPayload = null;
    $resolvedPayload = $order->getPayload(function ($loaded) use (&$callbackPayload): void {
        $callbackPayload = $loaded;
    });

    expect($order->orchestrator_priority)->toBe(50)
        ->and($order->type)->toBe('express-service')
        ->and($order->status)->toBe('driver_assigned')
        ->and($order->time_window_start->toDateTimeString())->toBe('2026-07-27 09:15:00')
        ->and($order->time_window_end)->toBeNull()
        ->and($scheduled->time_window_start->toDateTimeString())->toBe('2026-07-27 10:30:00')
        ->and($resolvedPayload)->toBe($payload)
        ->and($callbackPayload)->toBe($payload)
        ->and($order->loadedMissing)->toBe(['payload'])
        ->and($order->shouldResolvePayloadPlaceForTest(['pickup' => ['address' => 'A']], 'pickup'))->toBeTrue()
        ->and($order->shouldResolvePayloadPlaceForTest(['pickup' => 'already resolved'], 'pickup'))->toBeFalse()
        ->and($order->hasExistingPayloadPlaceUuidForTest(['pickup_uuid' => 'not-a-uuid'], 'pickup'))->toBeFalse()
        ->and($order->findClosestDrivers())->toBeInstanceOf(Collection::class)
        ->and($order->findClosestDrivers())->toBeEmpty();

    Carbon::setTestNow();
});

test('order route driver config activity and dynamic resolution branches use loaded state', function () {
    $order = new FleetOpsOrderUnitFake();
    $order->setRawAttributes([
        'uuid'                 => 'order-uuid',
        'order_uuid'           => 'legacy-order-uuid',
        'company_uuid'         => 'company-uuid',
        'driver_assigned_uuid' => null,
        'tracking_number_uuid' => 'tracking-number-uuid',
        'type'                 => 'default',
    ], true);

    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_PUBLIC',
    ], true);

    expect($order->assignDriver($driver, true))->toBe($order)
        ->and($order->driver_assigned_uuid)->toBe('driver-uuid')
        ->and($order->driverAssigned)->toBe($driver)
        ->and($order->saved)->toBeTrue()
        ->and($order->assignDriver($driver, true))->toBe($order)
        ->and($order->isDriver($driver))->toBeTrue()
        ->and($order->isDriver('driver-uuid'))->toBeTrue()
        ->and($order->isDriver('driver_PUBLIC'))->toBeTrue()
        ->and($order->isDriver(new stdClass()))->toBeFalse();

    $config = new FleetOpsOrderUnitConfigFake();
    $config->setRawAttributes([
        'uuid' => 'config-uuid',
        'key'  => 'scheduled-delivery',
    ], true);
    $config->flow      = [['code' => 'created']];
    $order->fakeConfig = $config;

    expect($order->ensureOrderConfig())->toBe($config)
        ->and($config->context)->toBe($order)
        ->and($order->filled)->toContain([
            'order_config_uuid' => 'config-uuid',
            'type'              => 'scheduled-delivery',
        ])
        ->and($order->orderConfig)->toBe($config)
        ->and($order->getConfigFlow())->toBe([['code' => 'created']]);

    $activity = new Activity(['code' => 'PICKED_UP']);
    $order->setRelation('trackingStatuses', collect([
        (object) ['code' => 'created'],
        (object) ['code' => 'picked_up'],
    ]));

    expect($order->hasCompletedActivity($activity))->toBeTrue()
        ->and($order->hasDispatchedStatus())->toBeFalse();

    $order->setRelation('trackingStatuses', collect([(object) ['code' => 'DISPATCHED']]));
    expect($order->hasDispatchedStatus())->toBeTrue();

    $pickup        = fleetopsOrderUnitPlace('pickup-uuid', new Point(1.1, 2.2));
    $dropoff       = fleetopsOrderUnitPlace('dropoff-uuid', new Point(3.3, 4.4));
    $waypointPlace = fleetopsOrderUnitPlace('waypoint-place-uuid', new Point(5.5, 6.6));
    $waypoint      = new FleetOpsOrderUnitWaypointFake();
    $waypoint->setRawAttributes(['place_uuid' => 'waypoint-place-uuid', 'name' => null], true);
    $waypoint->setRelation('place', $waypointPlace);

    $payload = new Payload();
    $payload->setRawAttributes(['current_waypoint_uuid' => 'waypoint-place-uuid'], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('currentWaypointMarker', $waypoint);
    $payload->setRelation('waypointMarkers', collect([$waypoint]));

    $customer = new Contact();
    $customer->setRawAttributes(['uuid' => 'customer-uuid'], true);
    $waypointCustomer = new Contact();
    $waypointCustomer->setRawAttributes(['uuid' => 'waypoint-customer-uuid'], true);
    $waypoint->setRelation('customer', $waypointCustomer);

    $order->setRelation('payload', $payload);
    $order->setRelation('customer', $customer);
    $order->setRawAttributes(array_merge($order->getAttributes(), ['public_id' => 'order_public']), true);
    $order->customFieldExists = true;
    $order->customValue       = 'custom value';
    $order->metaExists        = true;
    $order->metaValue         = 'meta value';

    expect($order->resolveDynamicProperty('pickup'))->toBe($pickup)
        ->and($order->resolveDynamicProperty('dropoff.uuid'))->toBe('dropoff-uuid')
        ->and($order->resolveDynamicProperty('waypoint.uuid'))->toBe('waypoint-place-uuid')
        ->and($waypoint->loadedMissing)->toBe(['place'])
        ->and($order->resolveDynamicProperty('publicId'))->toBe('order_public')
        ->and($order->resolveDynamicProperty('doorCode'))->toBe('custom value')
        ->and($order->resolveDynamicProperty('priority_note'))->toBe('meta value')
        ->and($order->resolveDynamicValue('missing_value'))->toBe('missing_value')
        ->and($order->resolveDynamicNotifiable('customer'))->toBe($waypointCustomer)
        ->and($order->resolveDynamicNotifiable('driverAssigned'))->toBe($driver);
});
