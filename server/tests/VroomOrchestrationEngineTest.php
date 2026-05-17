<?php

use Fleetbase\FleetOps\Orchestration\Engines\VroomOrchestrationEngine;

test('vroom maps multi stop route tasks to shipments', function () {
    $engine  = new VroomOrchestrationEngine();
    $method  = new ReflectionMethod($engine, 'mapTaskToShipment');
    $reverse = [];

    $method->setAccessible(true);

    $shipment = $method->invokeArgs($engine, [[
        'id'          => 'order_gkATxX2U3P',
        'stops'       => [
            ['role' => 'pickup', 'location' => [103.85, 1.28]],
            ['role' => 'waypoint', 'location' => [103.87, 1.30]],
            ['role' => 'dropoff', 'location' => [103.90, 1.31]],
        ],
        'service'     => 300,
        'amount'      => [1, 0, 0, 1],
        'description' => 'order_gkATxX2U3P',
    ], &$reverse]);

    expect($shipment)->toHaveKeys(['pickup', 'delivery', 'amount']);
    expect($shipment)->not->toHaveKey('location');
    expect($shipment['pickup']['location'])->toBe([103.85, 1.28]);
    expect($shipment['delivery']['location'])->toBe([103.90, 1.31]);
    expect($shipment['delivery']['service'])->toBe(300);
    expect(array_values($reverse))->toBe(['order_gkATxX2U3P', 'order_gkATxX2U3P']);
});

test('vroom maps single stop route tasks to jobs without pickup coordinates', function () {
    $engine  = new VroomOrchestrationEngine();
    $method  = new ReflectionMethod($engine, 'mapTaskToVroomJob');
    $reverse = [];

    $method->setAccessible(true);

    $job = $method->invokeArgs($engine, [[
        'id'          => 'order_waypointOnly',
        'stops'       => [
            ['role' => 'waypoint', 'location' => [103.86, 1.29]],
        ],
        'service'     => 300,
        'amount'      => [1, 0, 0, 1],
        'description' => 'order_waypointOnly',
    ], &$reverse]);

    expect($job)->toMatchArray([
        'location'    => [103.86, 1.29],
        'description' => 'order_waypointOnly',
        'service'     => 300,
        'amount'      => [1, 0, 0, 1],
    ]);
    expect($job)->not->toHaveKey('pickup');
    expect(array_values($reverse))->toBe(['order_waypointOnly']);
});

test('vroom dedupes unassigned pickup and delivery task ids to one order id', function () {
    $engine = new VroomOrchestrationEngine();
    $method = new ReflectionMethod($engine, 'mapVroomResponse');

    $method->setAccessible(true);

    $result = $method->invoke($engine, [
        'routes'     => [],
        'unassigned' => [
            ['id' => 1001],
            ['id' => 1002],
        ],
        'summary' => ['routes' => 0],
    ], [
        1001 => 'order_gkATxX2U3P',
        1002 => 'order_gkATxX2U3P',
    ]);

    expect($result['unassigned'])->toBe(['order_gkATxX2U3P']);
});

test('vroom maps shipment delivery steps back to fleetops assignments', function () {
    $engine = new VroomOrchestrationEngine();
    $method = new ReflectionMethod($engine, 'mapVroomResponse');

    $method->setAccessible(true);

    $result = $method->invoke($engine, [
        'routes' => [
            [
                'description' => json_encode(['vehicle_id' => 'vehicle_w747Bat', 'driver_id' => null]),
                'steps'       => [
                    ['type' => 'start'],
                    ['type' => 'pickup', 'id' => 1001],
                    ['type' => 'delivery', 'id' => 1002, 'arrival' => 1778918400, 'duration' => 900, 'distance' => 4200],
                    ['type' => 'end'],
                ],
            ],
        ],
        'unassigned' => [],
        'summary'    => ['routes' => 1],
    ], [
        1001 => 'order_gkATxX2U3P',
        1002 => 'order_gkATxX2U3P',
    ]);

    expect($result['assignments'])->toHaveCount(1);
    expect($result['assignments'][0])->toMatchArray([
        'order_id'   => 'order_gkATxX2U3P',
        'vehicle_id' => 'vehicle_w747Bat',
        'driver_id'  => null,
        'sequence'   => 1,
        'arrival'    => 1778918400,
        'duration'   => 900,
        'distance'   => 4200,
    ]);
    expect($result['summary'])->toBe(['routes' => 1]);
});

test('vroom capacity-only payload uses matrix indexes instead of coordinates', function () {
    $engine  = new VroomOrchestrationEngine();
    $method  = new ReflectionMethod($engine, 'buildCapacityOnlyPayload');
    $reverse = [];

    $method->setAccessible(true);

    $payload = $method->invokeArgs($engine, [[
        [
            'id'          => 'order_capacity',
            'description' => 'order_capacity',
            'amount'      => [100, 250, 1, 2],
            'skills'      => [123],
        ],
    ], [
        [
            'id'        => 'vehicle_capacity',
            'driver_id' => null,
            'capacity'  => [500, 1000, 4, 20],
            'skills'    => [123],
            'max_tasks' => 3,
        ],
    ], [], &$reverse]);

    expect($payload['vehicles'][0])->toMatchArray([
        'profile'     => 'capacity_only',
        'start_index' => 0,
        'capacity'    => [500, 1000, 4, 20],
        'skills'      => [123],
        'max_tasks'   => 3,
    ]);
    expect($payload['vehicles'][0])->not->toHaveKey('start');
    expect($payload['jobs'][0])->toMatchArray([
        'location_index' => 1,
        'delivery'       => [100, 250, 1, 2],
        'skills'         => [123],
    ]);
    expect($payload['jobs'][0])->not->toHaveKey('location');
    expect($payload['jobs'][0])->not->toHaveKey('pickup');
    expect($payload['matrices']['capacity_only']['durations'])->toHaveCount(2);
    expect(array_values($reverse))->toBe(['order_capacity']);
});
