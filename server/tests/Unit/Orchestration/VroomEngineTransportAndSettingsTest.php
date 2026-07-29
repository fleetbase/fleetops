<?php

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\FleetOps\Orchestration\Engines\env')) {
    eval('namespace Fleetbase\FleetOps\Orchestration\Engines; function env($key = null, $default = null) { return $default; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\VroomOrchestrationEngine;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Http;

/**
 * Covers the VROOM orchestration engine transport against a faked HTTP
 * layer: full allocation with job and shipment payloads mapped back to
 * assignments and unassigned orders, capacity-only early returns, task
 * mapping guards, error propagation from failed solves, and connection
 * settings resolved from organization and system settings rows with the
 * configured-value fallbacks.
 */
function fleetopsVroomBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('log', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);

    $schema = $connection->getSchemaBuilder();
    $schema->create('settings', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['key', 'value'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsVroomPlace(string $uuid, ?Point $location): Place
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => $uuid, 'name' => 'Stop ' . $uuid], true);
    if ($location) {
        $place->setAttribute('location', $location);
    }

    return $place;
}

function fleetopsVroomOrder(string $publicId, ?Place $pickup, ?Place $dropoff): Order
{
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-' . $publicId], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('entities', collect());
    $payload->setRelation('waypoints', collect());

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-' . $publicId, 'public_id' => $publicId], true);
    $order->setRelation('payload', $pickup || $dropoff ? $payload : null);

    return $order;
}

function fleetopsVroomVehicle(string $publicId, Point $location): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-' . $publicId, 'public_id' => $publicId], true);
    $vehicle->setAttribute('location', $location);
    $vehicle->setRelation('driver', null);

    return $vehicle;
}

test('allocate maps jobs shipments and vroom routes to assignments', function () {
    fleetopsVroomBoot();

    $shipmentOrder = fleetopsVroomOrder('order_vroom1', fleetopsVroomPlace('p-1', new Point(1.30, 103.80)), fleetopsVroomPlace('p-2', new Point(1.35, 103.85)));
    $jobOrder      = fleetopsVroomOrder('order_vroom2', fleetopsVroomPlace('p-3', new Point(1.31, 103.81)), null);
    $invalidOrder  = fleetopsVroomOrder('order_vroom3', null, null);
    $vehicle       = fleetopsVroomVehicle('vehicle_vroom1', new Point(1.20, 103.70));

    Http::fake(['*' => Http::response([
        'routes' => [[
            'description' => json_encode(['vehicle_id' => 'vehicle_vroom1', 'driver_id' => null]),
            'steps'       => [
                ['type' => 'start'],
                ['type' => 'delivery', 'id' => crc32('order_vroom1:delivery'), 'arrival' => 100, 'duration' => 50, 'distance' => 900],
                ['type' => 'job', 'id' => 999999],
            ],
        ]],
        'unassigned' => [['id' => crc32('order_vroom2')]],
        'summary'    => ['cost' => 5],
    ], 200)]);

    $engine = new VroomOrchestrationEngine();
    $result = $engine->allocate(collect([$shipmentOrder, $jobOrder, $invalidOrder]), collect([$vehicle]));

    expect($result['assignments'])->toHaveCount(1)
        ->and($result['assignments'][0]['order_id'])->toBe('order_vroom1')
        ->and($result['assignments'][0]['vehicle_id'])->toBe('vehicle_vroom1')
        ->and($result['unassigned'])->toContain('order_vroom2')
        ->and($result['unassigned'])->toContain('order_vroom3')
        ->and($result['summary']['cost'])->toBe(5);
});

test('capacity only allocation returns early when every task is invalid', function () {
    fleetopsVroomBoot();
    Http::fake(['*' => Http::response(['should' => 'not-be-called'], 500)]);

    $engine = new VroomOrchestrationEngine();
    $result = $engine->allocate(
        collect([fleetopsVroomOrder('order_vroomcap1', null, null)]),
        collect([fleetopsVroomVehicle('vehicle_vroomcap1', new Point(1.22, 103.72))]),
        ['allocation_strategy' => 'capacity_only']
    );

    expect($result['assignments'])->toBe([])
        ->and($result['summary']['allocation_strategy'])->toBe('capacity_only')
        ->and($result['unassigned'])->toContain('order_vroomcap1');
    Http::assertNothingSent();
});

test('task mapping guards reject stops without locations', function () {
    fleetopsVroomBoot();
    $engine = new VroomOrchestrationEngine();

    $job = new ReflectionMethod(VroomOrchestrationEngine::class, 'mapTaskToVroomJob');
    $job->setAccessible(true);
    $reverse = [];
    expect($job->invokeArgs($engine, [['id' => 'order_x', 'stops' => []], &$reverse]))->toBeNull();

    $shipment = new ReflectionMethod(VroomOrchestrationEngine::class, 'mapTaskToShipment');
    $shipment->setAccessible(true);
    expect($shipment->invokeArgs($engine, [['id' => 'order_y', 'stops' => [['role' => 'pickup'], ['role' => 'dropoff']]], &$reverse]))->toBeNull();

    $matrix = new ReflectionMethod(VroomOrchestrationEngine::class, 'buildUniformMatrix');
    $matrix->setAccessible(true);
    expect($matrix->invoke($engine, 2))->toBe([[0, 1], [1, 0]]);
});

test('failed solves raise runtime errors with the response detail', function () {
    fleetopsVroomBoot();
    Http::fake(['*' => Http::response('overloaded', 503)]);

    $engine   = new VroomOrchestrationEngine();
    $callable = new ReflectionMethod(VroomOrchestrationEngine::class, 'callVroom');
    $callable->setAccessible(true);

    expect(fn () => $callable->invoke($engine, ['jobs' => []]))
        ->toThrow(RuntimeException::class, 'VROOM returned an error');
});

test('connection settings resolve organization then system values', function () {
    $connection = fleetopsVroomBoot();
    $connection->table('settings')->insert([
        ['key' => 'company.company-1.vroom', 'value' => json_encode(['api_host' => 'https://org-vroom.test', 'endpoint_mode' => 'binary', 'api_key' => ' '])],
        ['key' => 'vroom', 'value' => json_encode(['api_key' => 'sys-key'])],
    ]);

    $engine = new VroomOrchestrationEngine();
    $call   = function (string $method) use ($engine) {
        $reflection = new ReflectionMethod(VroomOrchestrationEngine::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($engine);
    };

    expect($call('resolveVroomBaseUri'))->toBe('https://org-vroom.test')
        ->and($call('resolveVroomEndpointMode'))->toBe('binary')
        // Whitespace-only org values fall back to the system setting
        ->and($call('resolveVroomApiKey'))->toBe('sys-key');

    // Binary endpoints skip /solve and append the api key
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $callable = new ReflectionMethod(VroomOrchestrationEngine::class, 'callVroom');
    $callable->setAccessible(true);
    $result = $callable->invoke($engine, ['jobs' => []]);
    expect($result)->toBe(['ok' => true]);
    Http::assertSent(fn ($request) => $request->url() === 'https://org-vroom.test?api_key=sys-key');
});

test('unreachable vroom hosts raise a runtime error naming the host setting', function () {
    fleetopsVroomBoot();
    Http::fake(function () {
        throw new Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect');
    });

    $engine   = new VroomOrchestrationEngine();
    $callable = new ReflectionMethod(VroomOrchestrationEngine::class, 'callVroom');
    $callable->setAccessible(true);

    expect(fn () => $callable->invoke($engine, ['jobs' => []]))
        ->toThrow(RuntimeException::class, 'VROOM allocation engine is unavailable');
});

test('connection settings fall back to defaults when the settings table is absent', function () {
    $connection = fleetopsVroomBoot();
    $connection->getSchemaBuilder()->drop('settings');

    $engine = new VroomOrchestrationEngine();
    $call   = function (string $method) use ($engine) {
        $reflection = new ReflectionMethod(VroomOrchestrationEngine::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($engine);
    };

    // Minimal installs without a settings table still resolve defaults
    expect($call('resolveVroomBaseUri'))->toBeString()
        ->and($call('resolveVroomApiKey'))->toBeIn([null, '']);
});
