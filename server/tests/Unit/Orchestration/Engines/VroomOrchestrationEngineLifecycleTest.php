<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\VroomOrchestrationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsVroomLifecycleEngineProbe extends VroomOrchestrationEngine
{
    public array $payloads = [];

    protected function callVroom(array $vroomPayload): array
    {
        $this->payloads[] = $vroomPayload;
        $vehicle          = $vroomPayload['vehicles'][0] ?? ['description' => '{}'];
        $stepId           = $vroomPayload['shipments'][0]['delivery']['id'] ?? $vroomPayload['jobs'][0]['id'] ?? null;

        return [
            'routes' => $stepId ? [[
                'description' => $vehicle['description'],
                'steps'       => [
                    ['type' => 'start'],
                    ['type' => isset($vroomPayload['shipments']) ? 'delivery' : 'job', 'id' => $stepId, 'arrival' => 1778918400, 'duration' => 900, 'distance' => 4200],
                    ['type' => 'end'],
                ],
            ]] : [],
            'unassigned' => [],
            'summary'    => ['routes' => $stepId ? 1 : 0],
        ];
    }
}

class FleetOpsVroomLifecycleVehicleFake extends Vehicle
{
    public ?object $locationFake = null;
    public array $skillsFake     = [];

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationFake;
        }

        if ($key === 'skills') {
            return $this->skillsFake;
        }

        if ($key === 'custom_fields') {
            return [];
        }

        return parent::getAttribute($key);
    }
}

class FleetOpsVroomLifecycleDriverFake extends Driver
{
    public ?object $locationFake = null;
    public array $skillsFake     = [];

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationFake;
        }

        if ($key === 'skills') {
            return $this->skillsFake;
        }

        if ($key === 'custom_fields') {
            return [];
        }

        return parent::getAttribute($key);
    }
}

function fleetopsVroomLifecyclePoint(float $lng, float $lat): object
{
    return new class($lng, $lat) {
        public function __construct(private float $lng, private float $lat)
        {
        }

        public function getLng(): float
        {
            return $this->lng;
        }

        public function getLat(): float
        {
            return $this->lat;
        }
    };
}

function fleetopsVroomLifecyclePlace(float|string|null $lng, float|string|null $lat): object
{
    return (object) [
        'location' => $lng === null || $lat === null ? null : fleetopsVroomLifecyclePoint((float) $lng, (float) $lat),
    ];
}

function fleetopsVroomLifecyclePayload(?object $pickup = null, ?object $dropoff = null): object
{
    return new class($pickup, $dropoff) {
        public Collection $entities;
        public Collection $waypointMarkers;

        public function __construct(public ?object $pickup, public ?object $dropoff)
        {
            $this->entities = collect([
                (object) [
                    'weight'          => 250,
                    'weight_unit'     => 'kg',
                    'length'          => 1,
                    'width'           => 1,
                    'height'          => 1,
                    'dimensions_unit' => 'm',
                ],
            ]);
            $this->waypointMarkers = collect([(object) [
                'place'        => fleetopsVroomLifecyclePlace(103.87, 1.30),
                'public_id'    => 'waypoint_vroom',
                'uuid'         => 'waypoint-vroom-uuid',
                'order'        => 1,
                'service_time' => 600,
            ]]);
        }

        public function relationLoaded(string $relation): bool
        {
            return $relation === 'waypointMarkers';
        }
    };
}

function fleetopsVroomLifecycleOrder(
    string $publicId,
    ?object $payload = null,
    array $attributes = [],
): Order {
    $order = new Order();
    $order->setRawAttributes(array_merge([
        'uuid'                  => $publicId . '-uuid',
        'public_id'             => $publicId,
        'required_skills'       => ['fragile'],
        'custom_fields'         => [],
        'orchestrator_priority' => 7,
        'time_window_start'     => Carbon::parse('2026-07-27 08:00:00'),
        'time_window_end'       => Carbon::parse('2026-07-27 12:00:00'),
    ], $attributes), true);
    $order->setAppends([]);

    if ($payload) {
        $order->setRelation('payload', $payload);
    }

    return $order;
}

function fleetopsVroomLifecycleVehicle(
    string $publicId,
    ?object $location = null,
    ?Driver $driver = null,
    array $attributes = [],
): FleetOpsVroomLifecycleVehicleFake {
    $vehicle = new FleetOpsVroomLifecycleVehicleFake();
    $vehicle->setRawAttributes(array_merge([
        'uuid'                     => $publicId . '-uuid',
        'public_id'                => $publicId,
        'payload_capacity'         => 1000,
        'payload_capacity_volume'  => 2,
        'payload_capacity_pallets' => 4,
        'payload_capacity_parcels' => 20,
        'return_to_depot'          => true,
        'max_tasks'                => 3,
        'time_window_start'        => Carbon::parse('2026-07-27 07:00:00'),
        'time_window_end'          => Carbon::parse('2026-07-27 18:00:00'),
    ], $attributes), true);
    $vehicle->setAppends([]);
    $vehicle->locationFake = $location;
    $vehicle->skillsFake   = ['fragile'];

    $vehicle->setRelation('driver', $driver);

    return $vehicle;
}

function fleetopsVroomLifecycleDriver(string $publicId, ?object $location = null): FleetOpsVroomLifecycleDriverFake
{
    $driver = new FleetOpsVroomLifecycleDriverFake();
    $driver->setRawAttributes([
        'uuid'                 => $publicId . '-uuid',
        'public_id'            => $publicId,
        'max_travel_time'      => 3600,
        'time_window_start'    => Carbon::parse('2026-07-27 08:00:00'),
        'time_window_end'      => Carbon::parse('2026-07-27 16:00:00'),
    ], true);
    $driver->setAppends([]);
    $driver->locationFake = $location;
    $driver->skillsFake   = ['cold_chain'];

    return $driver;
}

test('vroom engine identifiers describe the adapter contract', function () {
    $engine = new VroomOrchestrationEngine();

    expect($engine->getName())->toBe('VROOM')
        ->and($engine->getIdentifier())->toBe('vroom');
});

test('vroom route allocation builds shipment payloads and maps delivery assignments', function () {
    $engine  = new FleetOpsVroomLifecycleEngineProbe();
    $driver  = fleetopsVroomLifecycleDriver('driver_route', fleetopsVroomLifecyclePoint(103.84, 1.27));
    $vehicle = fleetopsVroomLifecycleVehicle('vehicle_route', fleetopsVroomLifecyclePoint(103.83, 1.26), $driver);
    $order   = fleetopsVroomLifecycleOrder('order_route', fleetopsVroomLifecyclePayload(
        fleetopsVroomLifecyclePlace(103.85, 1.28),
        fleetopsVroomLifecyclePlace(103.90, 1.31),
    ));

    $result = $engine->allocate(collect([$order]), collect([$vehicle]), [
        'profile'  => 'cycling',
        'geometry' => true,
    ]);

    expect($result['assignments'])->toHaveCount(1)
        ->and($result['assignments'][0])->toMatchArray([
            'order_id'   => 'order_route',
            'vehicle_id' => 'vehicle_route',
            'driver_id'  => 'driver_route',
            'sequence'   => 1,
            'arrival'    => 1778918400,
            'duration'   => 900,
            'distance'   => 4200,
        ])
        ->and($result['unassigned'])->toBe([])
        ->and($result['summary'])->toBe(['routes' => 1]);

    $payload = $engine->payloads[0];

    expect($payload)->not->toHaveKey('jobs')
        ->and($payload['shipments'])->toHaveCount(1)
        ->and($payload['shipments'][0]['pickup']['location'])->toBe([103.85, 1.28])
        ->and($payload['shipments'][0]['delivery']['location'])->toBe([103.90, 1.31])
        ->and($payload['shipments'][0]['delivery']['service'])->toBe(600)
        ->and($payload['shipments'][0]['delivery']['time_windows'])->toHaveCount(1)
        ->and($payload['shipments'][0]['amount'])->toBe([250, 1000, 0, 1])
        ->and($payload['shipments'][0]['skills'])->not->toBeEmpty()
        ->and($payload['shipments'][0]['priority'])->toBe(7)
        ->and($payload['vehicles'][0])->toMatchArray([
            'start'           => [103.84, 1.27],
            'end'             => [103.84, 1.27],
            'profile'         => 'cycling',
            'capacity'        => [1000, 2000, 4, 20],
            'max_tasks'       => 3,
            'max_travel_time' => 3600,
        ])
        ->and($payload['vehicles'][0]['skills'])->not->toBeEmpty()
        ->and($payload['vehicles'][0]['time_window'])->toHaveCount(2)
        ->and($payload['options'])->toBe(['g' => true]);
});

test('vroom route allocation sends single-stop orders as jobs and drops the shipments key', function () {
    $engine  = new FleetOpsVroomLifecycleEngineProbe();
    $driver  = fleetopsVroomLifecycleDriver('driver_job', fleetopsVroomLifecyclePoint(103.84, 1.27));
    $vehicle = fleetopsVroomLifecycleVehicle('vehicle_job', fleetopsVroomLifecyclePoint(103.83, 1.26), $driver);
    // A pickup and no other stop, so the order routes as a one-stop task
    $order = fleetopsVroomLifecycleOrder('order_job', new class(fleetopsVroomLifecyclePlace(103.85, 1.28)) {
        public Collection $entities;
        public Collection $waypointMarkers;
        public ?object $dropoff = null;

        public function __construct(public ?object $pickup)
        {
            $this->entities        = collect();
            $this->waypointMarkers = collect();
        }
    });

    $engine->allocate(collect([$order]), collect([$vehicle]), ['profile' => 'driving']);

    $payload = $engine->payloads[0];

    expect($payload['jobs'])->toHaveCount(1)
        ->and($payload['jobs'][0]['location'])->toBe([103.85, 1.28])
        ->and($payload)->not->toHaveKey('shipments');
});

test('vroom route allocation merges invalid tasks without calling vroom', function () {
    $engine = new FleetOpsVroomLifecycleEngineProbe();
    $order  = fleetopsVroomLifecycleOrder('order_invalid', fleetopsVroomLifecyclePayload(
        fleetopsVroomLifecyclePlace(null, null),
        fleetopsVroomLifecyclePlace(103.90, 1.31),
    ));

    $result = $engine->allocate(collect([$order]), collect([
        fleetopsVroomLifecycleVehicle('vehicle_route', fleetopsVroomLifecyclePoint(103.83, 1.26)),
    ]));

    expect($engine->payloads)->toBe([])
        ->and($result['assignments'])->toBe([])
        ->and($result['unassigned'])->toBe(['order_invalid'])
        ->and($result['summary']['invalid'][0])->toMatchArray([
            'order_id' => 'order_invalid',
            'reason'   => 'Order pickup stop is missing valid coordinates.',
        ]);
});

test('vroom capacity-only allocation reports no available vehicle branches', function () {
    $engine = new FleetOpsVroomLifecycleEngineProbe();
    $order  = fleetopsVroomLifecycleOrder('order_capacity', fleetopsVroomLifecyclePayload());

    $result = $engine->allocate(collect([$order]), collect(), [
        'allocation_strategy' => 'capacity_only',
        'vehicle_packing'     => 'balanced',
    ]);

    expect($engine->payloads)->toBe([])
        ->and($result['assignments'])->toBe([])
        ->and($result['unassigned'])->toBe(['order_capacity'])
        ->and($result['summary'])->toMatchArray([
            'engine'              => 'vroom',
            'allocation_strategy' => 'capacity_only',
            'vehicle_packing'     => 'balanced',
            'vehicle_fixed_cost'  => null,
            'assigned'            => 0,
            'unassigned'          => 1,
        ])
        ->and($result['summary']['unassigned_reasons'][0])->toBe([
            'order_id' => 'order_capacity',
            'reason'   => 'no_available_vehicle',
        ]);
});

test('vroom capacity-only allocation maps solver responses and summary metadata', function () {
    $engine  = new FleetOpsVroomLifecycleEngineProbe();
    $driver  = fleetopsVroomLifecycleDriver('driver_capacity');
    $vehicle = fleetopsVroomLifecycleVehicle('vehicle_capacity', null, $driver, [
        'return_to_depot' => false,
    ]);
    $order = fleetopsVroomLifecycleOrder('order_capacity', fleetopsVroomLifecyclePayload(), [
        'orchestrator_priority' => 3,
    ]);

    $result = $engine->allocate(collect([$order]), collect([$vehicle]), [
        'allocation_strategy' => 'capacity_only',
        'respect_capacity'    => false,
        'respect_skills'      => false,
        'vehicle_fixed_cost'  => 50000,
    ]);

    expect($result['assignments'][0])->toMatchArray([
        'order_id'   => 'order_capacity',
        'vehicle_id' => 'vehicle_capacity',
        'driver_id'  => 'driver_capacity',
        'sequence'   => 1,
    ])
        ->and($result['summary'])->toMatchArray([
            'engine'              => 'vroom',
            'allocation_strategy' => 'capacity_only',
            'vehicle_packing'     => 'minimize_vehicles',
            'vehicle_fixed_cost'  => 50000,
        ]);

    $payload = $engine->payloads[0];

    expect($payload['vehicles'][0])->toMatchArray([
        'profile'     => 'capacity_only',
        'start_index' => 0,
        'costs'       => ['fixed' => 50000],
    ])
        ->and($payload['vehicles'][0])->not->toHaveKeys(['capacity', 'skills'])
        ->and($payload['jobs'][0])->toMatchArray([
            'location_index' => 1,
            'description'    => 'order_capacity',
            'priority'       => 3,
        ])
        ->and($payload['jobs'][0])->not->toHaveKeys(['delivery', 'skills'])
        ->and($payload['matrices']['capacity_only']['durations'])->toBe([
            [0, 1],
            [1, 0],
        ])
        ->and($payload['options'])->toBe(['g' => false]);
});
