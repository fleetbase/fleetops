<?php

use Fleetbase\FleetOps\Console\Commands\ProcessOperationalAlerts;
use Fleetbase\FleetOps\Console\Commands\SimulateGeofenceEvents;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OptimizeOrderRouteCapability;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

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
