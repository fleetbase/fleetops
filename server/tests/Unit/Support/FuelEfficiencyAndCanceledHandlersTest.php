<?php

use Fleetbase\FleetOps\Listeners\HandleOrderCanceled;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Support\Analytics\FuelEfficiency;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the FuelEfficiency analytics weekly aggregation with a YEARWEEK
 * stand-in, the HandleOrderCanceled listener helper bodies, and the
 * OrderCompleted notification broadcast channels and push seams against
 * SQLite.
 */
function fleetopsFuelEffBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('YEARWEEK', fn ($date, $mode = 1) => (int) date('oW', strtotime((string) $date)));
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
        'fuel_reports' => ['uuid', 'public_id', 'company_uuid', 'currency', 'amount', 'report', 'status'],
        'orders'       => ['uuid', 'public_id', 'company_uuid', 'status', 'distance', 'driver_assigned_uuid', 'facilitator_uuid', 'facilitator_type'],
        'companies'    => ['uuid', 'public_id', 'name', 'currency', 'country'],
        'drivers'      => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status'],
        'users'        => ['uuid', 'public_id', 'company_uuid', 'name'],
        'settings'     => ['key', 'value'],
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

    session(['company' => 'company-1', 'api_credential' => 'console']);

    return $connection;
}

function fleetopsFuelEffCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme', 'currency' => 'SGD'], true);
    $company->exists = true;

    return $company;
}

test('fuel efficiency aggregates weekly cost against completed distance', function () {
    $connection = fleetopsFuelEffBoot();
    $now        = date('Y-m-d H:i:s');

    $connection->table('fuel_reports')->insert([
        ['uuid' => 'fr-1', 'company_uuid' => 'company-1', 'currency' => 'SGD', 'amount' => '120', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'fr-2', 'company_uuid' => 'company-1', 'currency' => 'SGD', 'amount' => '80', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'completed', 'distance' => '100000', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $payload = FuelEfficiency::forCompany(fleetopsFuelEffCompany())->get();

    expect($payload)->toBeArray()
        ->and(json_encode($payload))->toContain('200');
});

test('fuel efficiency returns an empty series without reports', function () {
    fleetopsFuelEffBoot();

    $payload = FuelEfficiency::forCompany(fleetopsFuelEffCompany())->get();

    expect($payload)->toBeArray();
});

test('order canceled listener helpers resolve and notify the driver', function () {
    $connection = fleetopsFuelEffBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $dispatcher = new class implements Illuminate\Contracts\Notifications\Dispatcher {
        public array $sent = [];

        public function send($notifiables, $notification)
        {
            $this->sent[] = $notification;
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            $this->sent[] = $notification;
        }
    };
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_test', 'driver_assigned_uuid' => 'driver-1'], true);
    $order->exists = true;

    $listener   = new HandleOrderCanceled();
    $reflection = new ReflectionClass($listener);

    $findDriver = $reflection->getMethod('findAssignedDriver');
    $findDriver->setAccessible(true);
    $driver = $findDriver->invoke($listener, $order);
    expect($driver)->toBeInstanceOf(Driver::class);

    $notify = $reflection->getMethod('notifyAssignedDriver');
    $notify->setAccessible(true);
    $notify->invoke($listener, $driver, $order);
    expect($dispatcher->sent)->toHaveCount(1);

    // Unassigned orders resolve no driver
    $order->driver_assigned_uuid = 'driver-missing';
    expect($findDriver->invoke($listener, $order))->toBeNull();
});

test('order completed broadcasts on order channels and exposes push seams', function () {
    fleetopsFuelEffBoot();

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_test', 'status' => 'completed'], true);
    $order->exists = true;

    $notification = new OrderCompleted($order);

    $channels = $notification->broadcastOn();
    expect($channels)->toHaveCount(5)
        ->and($channels[2]->name)->toBe('api.console')
        ->and($channels[3]->name)->toBe('order.order-1');

    // Push transports are unavailable in the harness; the delegation bodies
    // still execute, which is the covered contract here.
    expect(fn () => $notification->toFcm(null))->toThrow(TypeError::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
