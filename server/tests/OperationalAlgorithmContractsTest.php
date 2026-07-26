<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\ProcessOperationalAlerts;
use Fleetbase\FleetOps\Console\Commands\SimulateGeofenceEvents;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\Engines\GreedyOrchestrationEngine;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OptimizeOrderRouteCapability;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;

class FleetOpsAssignmentDriverFake extends Driver
{
    public ?object $locationFake = null;
    public array $skillsFake     = [];
    public bool $onlineFake      = false;

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationFake;
        }

        if ($key === 'skills') {
            return $this->skillsFake;
        }

        if ($key === 'online') {
            return $this->onlineFake;
        }

        return parent::getAttribute($key);
    }
}

class FleetOpsAssignmentVehicleFake extends Vehicle
{
    public ?object $locationFake = null;

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationFake;
        }

        return parent::getAttribute($key);
    }
}

class FleetOpsGeofenceStateDbFake
{
    public array $tables = [];

    public function table(string $name): FleetOpsGeofenceStateTableFake
    {
        return new FleetOpsGeofenceStateTableFake($this, $name);
    }
}

class FleetOpsGeofenceStateTableFake
{
    public array $wheres = [];

    public function __construct(private FleetOpsGeofenceStateDbFake $db, private string $table)
    {
    }

    public function where(string $column, mixed $value): static
    {
        $this->wheres[$column] = $value;

        return $this;
    }

    public function first(): ?object
    {
        return $this->db->tables[$this->table]['first'] ?? null;
    }

    public function upsert(array $values, array $uniqueBy, array $update): void
    {
        $this->db->tables[$this->table]['upserts'][] = [$values, $uniqueBy, $update];
    }

    public function update(array $values): int
    {
        $this->db->tables[$this->table]['updates'][] = [$this->wheres, $values];

        return 1;
    }

    public function delete(): int
    {
        $this->db->tables[$this->table]['deletes'][] = $this->wheres;

        return 1;
    }
}

function fleetopsInvoke(object $object, string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $arguments);
}

function fleetopsLocation(float $lat, float $lng): object
{
    return new class($lat, $lng) {
        public function __construct(private float $lat, private float $lng)
        {
        }

        public function getLat(): float
        {
            return $this->lat;
        }

        public function getLng(): float
        {
            return $this->lng;
        }
    };
}

function fleetopsDriver(string $uuid, string $publicId, array $skills, bool $online, ?object $location = null): Driver
{
    $driver = new FleetOpsAssignmentDriverFake();
    $driver->forceFill([
        'uuid'      => $uuid,
        'public_id' => $publicId,
    ]);
    $driver->skillsFake   = $skills;
    $driver->onlineFake   = $online;
    $driver->locationFake = $location;
    $driver->setRelation('scheduleItems', collect());

    return $driver;
}

function fleetopsVehicle(string $uuid, string $publicId, ?object $location = null, ?Driver $driver = null): object
{
    $vehicle            = new stdClass();
    $vehicle->uuid      = $uuid;
    $vehicle->public_id = $publicId;
    $vehicle->location  = $location;
    $vehicle->driver    = $driver;

    return $vehicle;
}

function fleetopsOrder(string $publicId, ?object $pickup = null, ?object $dropoff = null, int $priority = 0, ?string $scheduledAt = null): object
{
    $order                        = new stdClass();
    $order->public_id             = $publicId;
    $order->orchestrator_priority = $priority;
    $order->scheduled_at          = $scheduledAt ? new DateTimeImmutable($scheduledAt) : null;
    $order->payload               = (object) [
        'pickup'  => $pickup,
        'dropoff' => $dropoff,
    ];

    return $order;
}

test('operational alert command extracts route points and distances defensively', function () {
    $command = new ProcessOperationalAlerts();

    expect(fleetopsInvoke($command, 'pointFromPair', [[1.25, 103.85]]))->toBeInstanceOf(Point::class)
        ->and(fleetopsInvoke($command, 'pointFromPair', [[103.85, 1.25]])->getLat())->toBe(1.25)
        ->and(fleetopsInvoke($command, 'pointFromPair', [['bad', 1.25]]))->toBeNull()
        ->and(fleetopsInvoke($command, 'pointFromPair', [[999, 999]]))->toBeNull()
        ->and(fleetopsInvoke($command, 'collectPoints', ['not-an-array']))->toBe([]);

    $points = fleetopsInvoke($command, 'collectPoints', [[
        'geometry' => [
            'coordinates' => [
                [103.85, 1.25],
                [103.86, 1.26],
            ],
        ],
    ]]);

    expect($points)->toHaveCount(2)
        ->and($points[0]->getLat())->toBe(1.25)
        ->and(fleetopsInvoke($command, 'minimumDistanceToRoute', [
            new Point(1.25, 103.85),
            $points,
        ]))->toBe(0.0);
});

test('geofence simulation parses events and selects state tables by subject type', function () {
    $command = new SimulateGeofenceEvents();

    expect(fleetopsInvoke($command, 'parseEvents', ['sequence']))->toBe(['entered', 'dwelled', 'exited'])
        ->and(fleetopsInvoke($command, 'parseEvents', [' entered,invalid,exited ']))->toBe(['entered', 'exited'])
        ->and(fleetopsInvoke($command, 'parseEvents', ['unknown']))->toBe([])
        ->and(fleetopsInvoke($command, 'stateTable', ['vehicle']))->toBe('vehicle_geofence_states')
        ->and(fleetopsInvoke($command, 'stateTable', ['driver']))->toBe('driver_geofence_states')
        ->and(fleetopsInvoke($command, 'subjectColumn', ['vehicle']))->toBe('vehicle_uuid')
        ->and(fleetopsInvoke($command, 'subjectColumn', ['driver']))->toBe('driver_uuid');
});

test('geofence simulation updates state records and derives dwell duration', function () {
    Carbon::setTestNow('2026-07-26 12:00:00');
    $db = new FleetOpsGeofenceStateDbFake();
    DB::swap($db);

    $command = new SimulateGeofenceEvents();
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid'], true);
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-uuid'], true);
    $serviceArea = new ServiceArea();
    $serviceArea->setRawAttributes(['uuid' => 'service-area-uuid'], true);
    $zone = new Zone();
    $zone->setRawAttributes(['uuid' => 'zone-uuid'], true);
    $enteredAt = Carbon::parse('2026-07-26 11:45:00');

    fleetopsInvoke($command, 'markInside', ['vehicle', $vehicle, $serviceArea, $enteredAt]);
    fleetopsInvoke($command, 'markOutside', ['vehicle', $vehicle, $serviceArea]);
    fleetopsInvoke($command, 'resetState', ['driver', $driver, $zone]);

    expect($db->tables['vehicle_geofence_states']['upserts'][0][0])->toMatchArray([
        'vehicle_uuid'  => 'vehicle-uuid',
        'geofence_uuid' => 'service-area-uuid',
        'geofence_type' => 'service_area',
        'is_inside'     => true,
        'entered_at'    => $enteredAt,
        'exited_at'     => null,
        'dwell_job_id'  => null,
    ])
        ->and($db->tables['vehicle_geofence_states']['upserts'][0][1])->toBe(['vehicle_uuid', 'geofence_uuid'])
        ->and($db->tables['vehicle_geofence_states']['updates'][0][0])->toBe([
            'vehicle_uuid'  => 'vehicle-uuid',
            'geofence_uuid' => 'service-area-uuid',
        ])
        ->and($db->tables['vehicle_geofence_states']['updates'][0][1])->toMatchArray([
            'is_inside'    => false,
            'dwell_job_id' => null,
        ])
        ->and($db->tables['driver_geofence_states']['deletes'][0])->toBe([
            'driver_uuid'   => 'driver-uuid',
            'geofence_uuid' => 'zone-uuid',
        ])
        ->and(fleetopsInvoke($command, 'calculateDwellMinutes', ['driver', $driver, $zone, 9]))->toBe(9);

    $db->tables['driver_geofence_states']['first'] = (object) [
        'entered_at' => '2026-07-26 11:30:00',
    ];

    expect(fleetopsInvoke($command, 'calculateDwellMinutes', ['driver', $driver, $zone, 9]))->toBe(30);

    $db->tables['driver_geofence_states']['first'] = null;
    fleetopsInvoke($command, 'ensureEnteredAt', ['driver', $driver, $zone, $enteredAt]);

    expect($db->tables['driver_geofence_states']['upserts'][0][0])->toMatchArray([
        'driver_uuid'   => 'driver-uuid',
        'geofence_uuid' => 'zone-uuid',
        'geofence_type' => 'zone',
        'is_inside'     => true,
        'entered_at'    => $enteredAt,
    ]);

    Carbon::setTestNow();
});

test('driver assignment helper scores skills online state and proximity', function () {
    $engine  = new DriverAssignmentEngine();
    $vehicle = new FleetOpsAssignmentVehicleFake();
    $vehicle->forceFill(['uuid' => 'vehicle-uuid', 'public_id' => 'vehicle_public']);
    $vehicle->locationFake = fleetopsLocation(1.30, 103.80);

    $matching = fleetopsDriver('driver-a', 'driver_a', ['hazmat', 'reefer'], true, fleetopsLocation(1.3005, 103.8005));
    $missing  = fleetopsDriver('driver-b', 'driver_b', ['hazmat'], true, fleetopsLocation(1.31, 103.81));
    $fallback = fleetopsDriver('driver-c', 'driver_c', [], false, null);

    expect(fleetopsInvoke($engine, 'findBestDriver', [$vehicle, collect([$missing, $matching]), ['hazmat', 'reefer'], true]))
        ->toBe($matching)
        ->and(fleetopsInvoke($engine, 'findBestDriver', [$vehicle, collect([$fallback]), ['missing'], true]))->toBeNull()
        ->and(fleetopsInvoke($engine, 'findBestDriver', [$vehicle, collect([$fallback]), ['missing'], false]))->toBe($fallback)
        ->and(fleetopsInvoke($engine, 'findBestDriver', [$vehicle, collect(), [], false]))->toBeNull()
        ->and(fleetopsInvoke($engine, 'aggregateRequiredSkills', [collect([
            new Order(['required_skills' => ['hazmat', 'liftgate']]),
            new Order(['required_skills' => ['hazmat', 'reefer']]),
        ])]))->toBe(['hazmat', 'liftgate', 'reefer'])
        ->and(fleetopsInvoke($engine, 'haversineDistance', [1.30, 103.80, 1.31, 103.81]))->toBeGreaterThan(0.0);
});

test('greedy orchestration engine assigns nearest available vehicles and reports overflow', function () {
    $engine     = new GreedyOrchestrationEngine();
    $nearDriver = fleetopsDriver('driver-a', 'driver_a', [], true, fleetopsLocation(1.3000, 103.8000));
    $farDriver  = fleetopsDriver('driver-b', 'driver_b', [], true, fleetopsLocation(1.4500, 103.9500));

    $vehicles = collect([
        fleetopsVehicle('vehicle-a', 'vehicle_a', fleetopsLocation(1.3000, 103.8000), $nearDriver),
        fleetopsVehicle('vehicle-b', 'vehicle_b', fleetopsLocation(1.4500, 103.9500), $farDriver),
    ]);

    $orders = collect([
        fleetopsOrder('order-low', (object) ['lat' => 1.451, 'lng' => 103.951], null, 1, '2026-01-02 10:00:00'),
        fleetopsOrder('order-high', (object) ['lat' => 1.301, 'lng' => 103.801], null, 10, '2026-01-03 10:00:00'),
        fleetopsOrder('order-overflow', (object) ['lat' => 1.302, 'lng' => 103.802], null, 0, '2026-01-01 10:00:00'),
    ]);

    $result = $engine->allocate($orders, $vehicles);

    expect($engine->getName())->toBe('Greedy (built-in)')
        ->and($engine->getIdentifier())->toBe('greedy')
        ->and($result['assignments'])->toHaveCount(2)
        ->and($result['assignments'][0])->toMatchArray([
            'order_id'   => 'order-high',
            'vehicle_id' => 'vehicle_a',
            'driver_id'  => 'driver_a',
            'sequence'   => 1,
        ])
        ->and($result['assignments'][0]['distance'])->toBeInt()
        ->and($result['assignments'][1])->toMatchArray([
            'order_id'   => 'order-low',
            'vehicle_id' => 'vehicle_b',
            'driver_id'  => 'driver_b',
            'sequence'   => 1,
        ])
        ->and($result['unassigned'])->toBe(['order-overflow'])
        ->and($result['summary'])->toBe([
            'engine'     => 'greedy',
            'assigned'   => 2,
            'unassigned' => 1,
        ]);
});

test('greedy orchestration engine supports multi order movement and no-location fallbacks', function () {
    $engine   = new GreedyOrchestrationEngine();
    $vehicles = collect([
        fleetopsVehicle('vehicle-a', 'vehicle_a', fleetopsLocation(1.3000, 103.8000)),
        fleetopsVehicle('vehicle-b', 'vehicle_b'),
    ]);

    $orders = collect([
        fleetopsOrder('first', (object) ['lat' => 1.301, 'lng' => 103.801], (object) ['lat' => 1.500, 'lng' => 104.000]),
        fleetopsOrder('second', (object) ['lat' => 1.501, 'lng' => 104.001], null),
        fleetopsOrder('no-pickup'),
    ]);

    $result = $engine->allocate($orders, $vehicles, ['allow_multi_order' => true]);

    expect($result['assignments'])->toHaveCount(3)
        ->and($result['assignments'][0])->toMatchArray([
            'order_id'   => 'first',
            'vehicle_id' => 'vehicle_a',
            'sequence'   => 1,
        ])
        ->and($result['assignments'][1])->toMatchArray([
            'order_id'   => 'second',
            'vehicle_id' => 'vehicle_a',
            'sequence'   => 2,
        ])
        ->and($result['assignments'][2])->toMatchArray([
            'order_id'   => 'no-pickup',
            'vehicle_id' => 'vehicle_a',
            'sequence'   => 3,
            'distance'   => 0,
        ])
        ->and($result['unassigned'])->toBe([])
        ->and($engine->allocate(collect([fleetopsOrder('fallback')]), collect([fleetopsVehicle('vehicle-empty', 'vehicle_empty')]))['assignments'][0])->toMatchArray([
            'order_id'   => 'fallback',
            'vehicle_id' => 'vehicle_empty',
            'driver_id'  => null,
            'distance'   => null,
        ]);
});

test('optimize order route capability exposes action metadata and route helpers', function () {
    $capability = (new ReflectionClass(OptimizeOrderRouteCapability::class))->newInstanceWithoutConstructor();

    expect($capability->key())->toBe('fleet-ops.optimize_order_route')
        ->and($capability->label())->toBe('Optimize Fleet-Ops order route')
        ->and($capability->description())->toContain('waypoint resequencing')
        ->and($capability->type())->toBe('action')
        ->and($capability->mode())->toBe('confirmation_required')
        ->and($capability->permissions())->toBe(['fleet-ops optimize order', 'fleet-ops update-route-for order'])
        ->and($capability->previewOnly())->toBeFalse()
        ->and($capability->executable())->toBeTrue()
        ->and($capability->inputSchema())->toHaveKeys(['order', 'waypoints'])
        ->and(fleetopsInvoke($capability, 'matchesPrompt', ['please optimize the route for this order']))->toBeTrue()
        ->and(fleetopsInvoke($capability, 'matchesPrompt', ['show vehicle status']))->toBeFalse();

    $pickup = new Place(['name' => 'Pickup']);
    $pickup->forceFill(['lat' => 1.30, 'lng' => 103.80]);
    $near = new Place(['name' => 'Near']);
    $near->forceFill(['lat' => 1.31, 'lng' => 103.81]);
    $far = new Place(['name' => 'Far']);
    $far->forceFill(['lat' => 1.40, 'lng' => 103.90]);

    $order = new Order();
    $order->setRelation('payload', (object) ['pickup' => $pickup]);

    $waypoints = collect([
        (object) ['uuid' => 'wp-far', 'place_uuid' => 'far-place', 'place' => $far, 'type' => 'dropoff'],
        (object) ['uuid' => 'wp-near', 'place_uuid' => 'near-place', 'place' => $near, 'type' => null],
    ]);

    $optimized = fleetopsInvoke($capability, 'optimizeWaypoints', [$order, $waypoints]);

    expect($optimized->pluck('uuid')->all())->toBe(['wp-near', 'wp-far'])
        ->and(fleetopsInvoke($capability, 'optimizeWaypoints', [$order, collect([$waypoints[0]])])->pluck('uuid')->all())->toBe(['wp-far'])
        ->and(fleetopsInvoke($capability, 'distance', [$pickup, $near]))->toBeGreaterThan(0.0)
        ->and(fleetopsInvoke($capability, 'distance', [null, $near]))->toBe(PHP_FLOAT_MAX)
        ->and(fleetopsInvoke($capability, 'stopLabels', [$waypoints])->all())->toBe(['Far', 'Near']);
});
