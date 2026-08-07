<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers OrchestrationPayloadBuilder route and vehicle task construction
 * with in-memory models: route tasks with stops, invalid-coordinate and
 * missing-payload reasons, scheduled and explicit time windows, skills and
 * priority, deprecated job filtering, capacity tasks, route stop candidates
 * from waypoint markers and plain waypoints, vehicle entries with driver
 * fallbacks, depot returns, max-task, time-window and merged skill
 * handling, and skill code resolution.
 */
function fleetopsOrchTasksBoot(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsOrchTasksPlace(string $uuid, ?Point $location): Place
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => $uuid, 'name' => 'Stop ' . $uuid], true);
    if ($location) {
        $place->setAttribute('location', $location);
    }

    return $place;
}

function fleetopsOrchTasksOrder(array $attributes, ?Payload $payload = null): Order
{
    $order = new Order();
    $order->setRawAttributes($attributes, true);
    $order->setRelation('payload', $payload);

    return $order;
}

function fleetopsOrchTasksPayload(?Place $pickup, ?Place $dropoff, $waypointMarkers = null): Payload
{
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1'], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('entities', collect());
    $payload->setRelation('waypoints', collect());
    if ($waypointMarkers !== null) {
        $payload->setRelation('waypointMarkers', $waypointMarkers);
    }

    return $payload;
}

test('route tasks build stops time windows skills and priorities', function () {
    fleetopsOrchTasksBoot();

    $payload = fleetopsOrchTasksPayload(
        fleetopsOrchTasksPlace('p-1', new Point(1.30, 103.80)),
        fleetopsOrchTasksPlace('p-2', new Point(1.35, 103.85))
    );
    $order = fleetopsOrchTasksOrder([
        'uuid'                  => 'order-1',
        'public_id'             => 'order_route1',
        'scheduled_at'          => '2026-08-01 09:00:00',
        'orchestrator_priority' => 7,
        'meta'                  => json_encode(['service_time_seconds' => 120]),
    ], $payload);
    $order->setRelation('trackingStatuses', collect());

    $tasks = OrchestrationPayloadBuilder::buildRouteTasks(collect([$order]));
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['stops'])->toHaveCount(2)
        ->and($tasks[0]['service'])->toBe(120)
        ->and($tasks[0]['time_windows'][0][1] - $tasks[0]['time_windows'][0][0])->toBe(4 * 3600)
        ->and($tasks[0]['priority'])->toBe(7);

    // Explicit time windows outrank the scheduled fallback
    $windowed = fleetopsOrchTasksOrder([
        'uuid'              => 'order-2',
        'public_id'         => 'order_route2',
        'time_window_start' => '2026-08-01 08:00:00',
        'time_window_end'   => '2026-08-01 12:00:00',
    ], fleetopsOrchTasksPayload(
        fleetopsOrchTasksPlace('p-3', new Point(1.31, 103.81)),
        fleetopsOrchTasksPlace('p-4', new Point(1.36, 103.86))
    ));
    $windowedTask = OrchestrationPayloadBuilder::buildRouteTasks(collect([$windowed]))[0];
    expect($windowedTask['time_windows'][0][1] - $windowedTask['time_windows'][0][0])->toBe(4 * 3600);

    // A stop without coordinates marks the whole task invalid
    $invalid = fleetopsOrchTasksOrder([
        'uuid'      => 'order-3',
        'public_id' => 'order_route3',
    ], fleetopsOrchTasksPayload(
        fleetopsOrchTasksPlace('p-5', null),
        fleetopsOrchTasksPlace('p-6', new Point(1.37, 103.87))
    ));
    $invalidTask = OrchestrationPayloadBuilder::buildRouteTasks(collect([$invalid]))[0];
    expect($invalidTask['invalid'] ?? false)->toBeTrue();

    // No payload places at all yields the no-stops reason
    $bare     = fleetopsOrchTasksOrder(['uuid' => 'order-4', 'public_id' => 'order_route4'], fleetopsOrchTasksPayload(null, null));
    $bareTask = OrchestrationPayloadBuilder::buildRouteTasks(collect([$bare]))[0];
    expect($bareTask['invalid'] ?? false)->toBeTrue()
        ->and($bareTask['reason'])->toContain('no routable');

    // The deprecated job builder filters invalid tasks out
    $jobs = OrchestrationPayloadBuilder::buildJobs(collect([$order, $invalid]));
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['id'])->toBe('order_route1');
});

test('capacity tasks flag missing payloads and carry demand vectors', function () {
    fleetopsOrchTasksBoot();

    $withPayload = fleetopsOrchTasksOrder([
        'uuid'      => 'order-1',
        'public_id' => 'order_cap1',
    ], fleetopsOrchTasksPayload(null, null));

    $withoutPayload = fleetopsOrchTasksOrder(['uuid' => 'order-2', 'public_id' => 'order_cap2'], null);

    $tasks = OrchestrationPayloadBuilder::buildCapacityTasks(collect([$withPayload, $withoutPayload]));
    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]['amount'])->toBeArray()
        ->and($tasks[1]['invalid'])->toBeTrue()
        ->and($tasks[1]['amount'])->toBe([0, 0, 0, 0]);
});

test('route stop candidates use waypoint markers then plain waypoints', function () {
    fleetopsOrchTasksBoot();

    $markerPlace = fleetopsOrchTasksPlace('p-1', new Point(1.32, 103.82));
    $marker      = new Waypoint();
    $marker->setRawAttributes(['uuid' => 'wp-1', 'public_id' => 'waypoint_orch1', 'order' => 0], true);
    $marker->setRelation('place', $markerPlace);
    $marker->setRelation('trackingNumber', null);

    $payload = fleetopsOrchTasksPayload(
        fleetopsOrchTasksPlace('p-0', new Point(1.30, 103.80)),
        fleetopsOrchTasksPlace('p-9', new Point(1.39, 103.89)),
        collect([$marker])
    );
    $order = fleetopsOrchTasksOrder(['uuid' => 'order-1', 'public_id' => 'order_stops1'], $payload);

    $stops = OrchestrationPayloadBuilder::buildRouteStops($order);
    expect($stops)->toHaveCount(3)
        ->and($stops[1]['role'])->toBe('waypoint')
        ->and($stops[1]['waypoint_id'])->toBe('waypoint_orch1')
        ->and($stops[1]['location'])->toBeArray();

    // Plain waypoint places are used when no markers are loaded
    $plainPayload = fleetopsOrchTasksPayload(null, null);
    $plainPayload->setRelation('waypoints', collect([fleetopsOrchTasksPlace('p-2', new Point(1.33, 103.83))]));
    $plainOrder = fleetopsOrchTasksOrder(['uuid' => 'order-2', 'public_id' => 'order_stops2'], $plainPayload);

    $plainStops = OrchestrationPayloadBuilder::buildRouteStops($plainOrder);
    expect($plainStops)->toHaveCount(1)
        ->and($plainStops[0]['role'])->toBe('waypoint');
});

test('vehicle entries resolve drivers depots windows and skills', function () {
    fleetopsOrchTasksBoot();

    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'              => 'driver-1',
        'public_id'         => 'driver_orch1',
        // Driver has no datetime cast for windows, so store Carbon values
        'time_window_start' => Carbon::parse('2026-08-01 08:00:00'),
        'time_window_end'   => Carbon::parse('2026-08-01 18:00:00'),
        'skills'            => json_encode(['hazmat']),
    ], true);
    $driver->setAttribute('location', new Point(1.20, 103.70));

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'            => 'vehicle-1',
        'public_id'       => 'vehicle_orch1',
        'return_to_depot' => 1,
        'max_tasks'       => 5,
        'skills'          => json_encode(['cold_chain']),
    ], true);
    $vehicle->setAttribute('location', new Point(1.21, 103.71));
    $vehicle->setRelation('driver', $driver);

    $entries = OrchestrationPayloadBuilder::buildVehicles(collect([$vehicle]));
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['driver_id'])->toBe('driver_orch1')
        ->and($entries[0]['start'])->toBe([103.7, 1.2])
        ->and($entries[0]['end'])->toBe([103.7, 1.2])
        ->and($entries[0]['max_tasks'])->toBe(5)
        ->and($entries[0]['time_window'][1] - $entries[0]['time_window'][0])->toBe(10 * 3600)
        ->and($entries[0]['skills'])->toHaveCount(2);

    // Vehicles without any start location are dropped
    $bare = new Vehicle();
    $bare->setRawAttributes(['uuid' => 'vehicle-2', 'public_id' => 'vehicle_orch2'], true);
    $bare->setRelation('driver', null);
    expect(OrchestrationPayloadBuilder::buildVehiclesOnly(collect([$bare])))->toBe([]);

    // Vehicle-only entries carry their own window and depot return
    $solo = new Vehicle();
    $solo->setRawAttributes([
        'uuid'              => 'vehicle-3',
        'public_id'         => 'vehicle_orch3',
        'return_to_depot'   => 1,
        'max_tasks'         => 3,
        'time_window_start' => Carbon::parse('2026-08-01 07:00:00'),
        'time_window_end'   => Carbon::parse('2026-08-01 15:00:00'),
        'skills'            => json_encode(['fragile']),
    ], true);
    $solo->setAttribute('location', new Point(1.25, 103.75));
    $solo->setRelation('driver', null);

    $soloEntries = OrchestrationPayloadBuilder::buildVehiclesOnly(collect([$solo]));
    expect($soloEntries)->toHaveCount(1)
        ->and($soloEntries[0]['end'])->toBe([103.75, 1.25])
        ->and($soloEntries[0]['max_tasks'])->toBe(3)
        ->and($soloEntries[0]['time_window'][1] - $soloEntries[0]['time_window'][0])->toBe(8 * 3600)
        ->and($soloEntries[0]['skills'])->toHaveCount(1);

    // Capacity vehicles honor driver windows first then vehicle windows
    $capacityEntries = OrchestrationPayloadBuilder::buildCapacityVehicles(collect([$vehicle, $solo]));
    expect($capacityEntries)->toHaveCount(2)
        ->and($capacityEntries[0]['time_window'][1] - $capacityEntries[0]['time_window'][0])->toBe(10 * 3600)
        ->and($capacityEntries[1]['time_window'][1] - $capacityEntries[1]['time_window'][0])->toBe(8 * 3600);
});

test('skill codes hash strings and boolean custom fields uniquely', function () {
    fleetopsOrchTasksBoot();

    $codes = OrchestrationPayloadBuilder::resolveSkills(['cold_chain', 'cold_chain', ''], ['certified' => true, 'insured' => '1', 'ignored' => false]);
    expect($codes)->toHaveCount(3)
        ->and(collect($codes)->every(fn ($code) => is_int($code) && $code > 0))->toBeTrue();

    $legacy = new ReflectionMethod(OrchestrationPayloadBuilder::class, 'extractSkillsFromCustomFields');
    $legacy->setAccessible(true);
    expect($legacy->invoke(null, ['certified' => 'true']))->toHaveCount(1);
});
