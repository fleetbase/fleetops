<?php

use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Str;

/**
 * Covers the real HandleOrderDispatched protected helper implementations
 * (the concrete-listener test overrides them): the dispatch-failed event
 * construction, dispatch-activity existence check, the nearby-available
 * driver spatial query with the company/user/driver whereHas chain, and
 * the driver notification helpers.
 *
 * The `users.driver` relation is registered through the core Expandable
 * `expand()` mechanism — the core model's `__call` forwards unknown methods
 * straight to the query builder, so Eloquent's `resolveRelationUsing`
 * resolvers are never consulted.
 */
if (!function_exists('Fleetbase\FleetOps\Listeners\event')) {
    eval('namespace Fleetbase\FleetOps\Listeners; function event($event = null) { \FleetOpsDispatchHelperRecorder::$events[] = $event; return [$event]; }');
}

class FleetOpsDispatchHelperRecorder
{
    public static array $events = [];
}

class FleetOpsDispatchHelperProbe extends HandleOrderDispatched
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsDispatchNotificationFake implements Illuminate\Contracts\Notifications\Dispatcher
{
    public array $sent = [];

    public function send($notifiables, $notification)
    {
        $this->sent[] = $notification;
    }

    public function sendNow($notifiables, $notification, ?array $channels = null)
    {
        $this->sent[] = $notification;
    }
}

function fleetopsDispatchHelperBoot(): SQLiteConnection
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('st_distance_sphere', fn ($a, $b) => 100.0);
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
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'status', 'adhoc', 'dispatched', 'dispatched_at', 'payload_uuid', 'meta', 'type'],
        'tracking_statuses' => ['uuid', 'company_uuid', 'code', 'tracking_number_uuid', 'status', 'details'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'status_uuid', 'type'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'meta'],
        'companies'         => ['uuid', 'public_id', 'name'],
        'company_users'     => ['uuid', 'company_uuid', 'user_uuid'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'phone', 'email'],
        'vehicles'          => ['uuid', 'public_id', 'company_uuid'],
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

    session(['company' => 'company-1']);
    Fleetbase\Models\User::expand('driver', function () {
        return $this->hasOne(Driver::class, 'user_uuid', 'uuid')->withoutGlobalScopes();
    });
    FleetOpsDispatchHelperRecorder::$events = [];

    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Driver One']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'available', 'online' => '1', 'location' => 'POINT(1 1)']);

    return $connection;
}

test('dispatch failed helper emits the lifecycle failure event', function () {
    fleetopsDispatchHelperBoot();
    $order = Order::where('uuid', 'order-1')->withoutGlobalScopes()->first();

    $probe = new FleetOpsDispatchHelperProbe();
    $probe->callHelper('dispatchFailed', $order, 'Order was dispatched, but driver was unable to be notified.');

    expect(FleetOpsDispatchHelperRecorder::$events)->toHaveCount(1)
        ->and(FleetOpsDispatchHelperRecorder::$events[0])->toBeInstanceOf(OrderDispatchFailed::class)
        ->and(FleetOpsDispatchHelperRecorder::$events[0]->getReason())->toContain('unable to be notified');
});

test('dispatch activity existence check reflects tracking statuses', function () {
    $connection = fleetopsDispatchHelperBoot();
    $order      = Order::where('uuid', 'order-1')->withoutGlobalScopes()->first();

    $probe = new FleetOpsDispatchHelperProbe();
    expect($probe->callHelper('doesntHaveDispatchActivity', $order))->toBeTrue();

    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'code' => 'DISPATCHED', 'tracking_number_uuid' => 'tn-1']);
    expect($probe->callHelper('doesntHaveDispatchActivity', $order))->toBeFalse();
});

test('nearby available drivers runs the spatial company scoped query', function () {
    $connection = fleetopsDispatchHelperBoot();

    $probe   = new FleetOpsDispatchHelperProbe();
    $drivers = $probe->callHelper('nearbyAvailableDrivers', new Point(1.3, 103.8), 5000);

    expect($drivers)->toHaveCount(1)
        ->and($drivers->first()->uuid)->toBe('driver-1');

    // Offline drivers are excluded
    $connection->table('drivers')->where('uuid', 'driver-1')->update(['online' => '0']);
    expect($probe->callHelper('nearbyAvailableDrivers', new Point(1.3, 103.8), 5000))->toHaveCount(0);
});

test('assigned driver lookup and notification helpers resolve and send', function () {
    fleetopsDispatchHelperBoot();
    $order                       = Order::where('uuid', 'order-1')->withoutGlobalScopes()->first();
    $order->driver_assigned_uuid = 'driver-1';

    $probe  = new FleetOpsDispatchHelperProbe();
    $driver = $probe->callHelper('findAssignedDriver', $order);
    expect($driver)->toBeInstanceOf(Driver::class)
        ->and($driver->uuid)->toBe('driver-1');

    $order->driver_assigned_uuid = 'driver-missing';
    expect($probe->callHelper('findAssignedDriver', $order))->toBeNull();

    $dispatcher = new FleetOpsDispatchNotificationFake();
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

    $probe->callHelper('notifyAssignedDriver', $driver, $order);
    $driver->distance = 250;
    $probe->callHelper('notifyAdhocDriver', $driver, $order);

    expect($dispatcher->sent)->toHaveCount(2);
});
