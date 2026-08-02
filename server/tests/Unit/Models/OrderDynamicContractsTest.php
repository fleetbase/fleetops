<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;

class FleetOpsOrderDynamicFake extends Order
{
    public array $loadedMissing     = [];
    public array $loaded            = [];
    public bool $saved              = false;
    public ?OrderConfig $fakeConfig = null;
    public array $customFields      = [];
    public array $metaValues        = [];

    public function config(): ?OrderConfig
    {
        return $this->fakeConfig;
    }

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function isCustomField(string $key): bool
    {
        return array_key_exists($key, $this->customFields);
    }

    public function getCustomFieldValueByKey(string $key, mixed $default = null): mixed
    {
        return $this->customFields[$key] ?? $default;
    }

    public function hasMeta($keys): bool
    {
        return array_key_exists($keys, $this->metaValues);
    }

    public function getMeta($key = null, $defaultValue = null)
    {
        return $key === null ? $this->metaValues : ($this->metaValues[$key] ?? $defaultValue);
    }
}

class FleetOpsOrderDynamicWaypointFake extends Waypoint
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

function fleetopsOrderDynamicPlace(string $name): Place
{
    $place       = new Place();
    $place->uuid = "{$name}-uuid";
    $place->name = ucfirst($name);

    return $place;
}

test('order config helpers persist normalized config context and expose flow arrays', function () {
    $config       = new OrderConfig();
    $config->uuid = 'config-uuid';
    $config->key  = 'last_mile';
    $config->flow = [['code' => 'created'], ['code' => 'dispatched']];

    $order                    = new FleetOpsOrderDynamicFake();
    $order->order_config_uuid = 'old-config';
    $order->type              = 'default';
    $order->fakeConfig        = $config;

    expect($order->ensureOrderConfig())->toBe($config)
        ->and($order->order_config_uuid)->toBe('config-uuid')
        ->and($order->type)->toBe('last-mile')
        ->and($order->saved)->toBeTrue()
        ->and($order->relationLoaded('orderConfig'))->toBeTrue()
        ->and($order->getConfigFlow())->toBe([['code' => 'created'], ['code' => 'dispatched']]);

    $order->fakeConfig->flow = 'invalid';

    expect($order->getConfigFlow())->toBe([]);
});

test('order dynamic property resolution reads payload targets attributes custom fields and meta', function () {
    $pickup = fleetopsOrderDynamicPlace('pickup');
    $place  = fleetopsOrderDynamicPlace('waypoint-place');

    $waypoint             = new FleetOpsOrderDynamicWaypointFake();
    $waypoint->place_uuid = $place->uuid;
    $waypoint->setRelation('place', $place);

    $payload = new Payload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('currentWaypointMarker', $waypoint);

    $order = new FleetOpsOrderDynamicFake();
    $order->setRawAttributes([
        'driver_assigned_uuid' => 'driver-uuid',
        'meta'                 => ['priority' => 'rush'],
    ], true);
    $order->customFields = ['gate_code' => 'A-19'];
    $order->metaValues   = ['priority' => 'rush'];
    $order->setRelation('payload', $payload);

    expect($order->resolveDynamicProperty('pickup.name'))->toBe('Pickup')
        ->and($order->resolveDynamicProperty('currentWaypoint.name'))->toBe('Waypoint-place')
        ->and($waypoint->loadedMissing)->toBe(['place'])
        ->and($order->resolveDynamicProperty('driverAssignedUuid'))->toBe('driver-uuid')
        ->and($order->resolveDynamicProperty('gate_code'))->toBe('A-19')
        ->and($order->resolveDynamicProperty('priority'))->toBe('rush')
        ->and($order->resolveDynamicValue('missing.value'))->toBe('missing.value');
});

test('order dynamic notifiable prefers current waypoint customer before order customer', function () {
    $orderCustomer    = (object) ['uuid' => 'order-customer'];
    $waypointCustomer = (object) ['uuid' => 'waypoint-customer'];

    $waypoint             = new FleetOpsOrderDynamicWaypointFake();
    $waypoint->place_uuid = 'current-place';
    $waypoint->setRelation('customer', $waypointCustomer);

    $payload                        = new Payload();
    $payload->current_waypoint_uuid = 'current-place';
    $payload->setRelation('waypointMarkers', collect([$waypoint]));

    $order = new FleetOpsOrderDynamicFake();
    $order->setRelation('payload', $payload);
    $order->setRelation('customer', $orderCustomer);

    expect($order->resolveDynamicNotifiable('customer'))->toBe($waypointCustomer)
        ->and($waypoint->loadedMissing)->toBe(['customer']);

    $order->setRelation('driverAssigned', (object) ['uuid' => 'driver-uuid']);

    expect($order->resolveDynamicNotifiable('driverAssigned')->uuid)->toBe('driver-uuid');
});

test('order activity and dispatched helpers use loaded tracking status relations', function () {
    $completed       = new TrackingStatus();
    $completed->code = 'DELIVERED';

    $dispatched       = new TrackingStatus();
    $dispatched->code = 'Dispatched';

    $order = new FleetOpsOrderDynamicFake();
    $order->setRelation('trackingStatuses', collect([$completed, $dispatched]));

    expect($order->hasCompletedActivity(new Activity(['code' => 'delivered'])))->toBeTrue()
        ->and($order->hasCompletedActivity(new Activity(['code' => 'canceled'])))->toBeFalse()
        ->and($order->hasDispatchedStatus())->toBeTrue();
});
