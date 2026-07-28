<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OptimizeOrderRouteCapability;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the OptimizeOrderRouteCapability apply flow against SQLite as an
 * admin session user: the not-ready and missing-order and
 * insufficient-waypoint guards, the successful waypoint update transaction
 * marking the order optimized, prompt matching, and order resolution from
 * prompt search terms.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsOptimizeRouteBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'            => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'orders'           => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'status', 'is_route_optimized', 'meta'],
        'payloads'         => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'meta'],
        'places'           => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'type', '_import_id'],
        'waypoints'        => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type', '_import_id', 'customer_uuid', 'customer_type'],
        'entities'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type', '_import_id'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'status_uuid', 'region', 'location', 'qr_code', 'barcode', '_key'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    // Preload the Contact class so the miscased 'fleetops:contact' default
    // mutation type resolves case-insensitively in the harness autoloader
    class_exists(Fleetbase\FleetOps\Models\Contact::class);

    $connection->table('users')->insert(['uuid' => 'admin-1', 'company_uuid' => 'company-1', 'type' => 'admin']);
    session(['company' => 'company-1', 'user' => 'admin-1']);

    return $connection;
}

function fleetopsOptimizeRouteTask(string $prompt): AiTask
{
    // AiTask is a lightweight bootstrap stand-in hydrated via constructor
    return new AiTask(['uuid' => 'task-1', 'prompt' => $prompt]);
}

test('apply guards not ready previews missing orders and waypoint counts', function () {
    $connection = fleetopsOptimizeRouteBoot();
    $capability = new OptimizeOrderRouteCapability();
    $task       = fleetopsOptimizeRouteTask('optimize the route for order ORD-1');

    expect(fn () => $capability->apply($task, ['ready' => false]))
        ->toThrow(RuntimeException::class, 'not ready to apply');

    expect(fn () => $capability->apply($task, ['ready' => true, 'draft' => ['order_uuid' => 'order-missing']]))
        ->toThrow(RuntimeException::class, 'Unable to find');

    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_route', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1']);

    expect(fn () => $capability->apply($task, ['ready' => true, 'draft' => ['order_uuid' => 'order-1', 'waypoints' => [['place_uuid' => 'place-1']]]]))
        ->toThrow(RuntimeException::class, 'enough waypoints');
});

test('apply persists optimized waypoints and marks the order', function () {
    $connection = fleetopsOptimizeRouteBoot();
    $capability = new OptimizeOrderRouteCapability();
    $task       = fleetopsOptimizeRouteTask('optimize the route for order ORD-1');

    $connection->table('places')->insert([
        ['uuid' => 'place-1', 'company_uuid' => 'company-1', 'name' => 'Stop One'],
        ['uuid' => 'place-2', 'company_uuid' => 'company-1', 'name' => 'Stop Two'],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_route', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1']);

    $result = $capability->apply($task, ['ready' => true, 'draft' => [
        'order_uuid' => 'order-1',
        'waypoints'  => [
            ['place_uuid' => 'place-1', 'order' => 0],
            ['place_uuid' => 'place-2', 'order' => 1],
        ],
    ]]);

    expect($result['status'])->toBe('completed')
        ->and($result['resource']['uuid'])->toBe('order-1')
        ->and($connection->table('orders')->value('is_route_optimized'))->not->toBeNull();
});

test('prompt matching and order resolution use search terms', function () {
    $connection = fleetopsOptimizeRouteBoot();
    $capability = new OptimizeOrderRouteCapability();

    $matches = new ReflectionMethod(OptimizeOrderRouteCapability::class, 'matchesPrompt');
    $matches->setAccessible(true);
    expect($matches->invoke($capability, 'optimize route for order ord-1'))->toBeTrue()
        ->and($matches->invoke($capability, 'show me my orders'))->toBeFalse();

    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_route77', 'internal_id' => 'ORD-77', 'company_uuid' => 'company-1']);

    $resolve = new ReflectionMethod(OptimizeOrderRouteCapability::class, 'resolveOrders');
    $resolve->setAccessible(true);
    $orders = $resolve->invoke($capability, fleetopsOptimizeRouteTask('optimize route for ORD-77'));

    expect($orders)->toHaveCount(1)
        ->and($orders->first()->uuid)->toBe('order-1');
});
