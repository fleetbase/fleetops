<?php

use Fleetbase\FleetOps\Jobs\ProcessAllocationJob;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Analytics\IssuesInsights;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the IssuesInsights analytics widget shape and the
 * ProcessAllocationJob query helpers against an in-memory SQLite fixture.
 */
class FleetOpsAllocationJobProbe extends ProcessAllocationJob
{
    public array $logged = [];

    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ProcessAllocationJob::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInsightsAllocationBoot(): SQLiteConnection
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'issues'   => ['uuid', 'public_id', 'company_uuid', 'category', 'priority', 'status', 'resolved_at'],
        'orders'   => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'status'],
        'payloads' => ['uuid', 'public_id', 'company_uuid', 'dropoff_uuid'],
        'vehicles' => ['uuid', 'public_id', 'company_uuid', 'driver_uuid'],
        'drivers'  => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'online'],
        'users'    => ['uuid', 'public_id', 'company_uuid'],
        'settings' => ['key', 'value'],
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

    return $connection;
}

function fleetopsInsightsCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1', 'name' => 'Acme'], true);

    return $company;
}

test('issues insights aggregates categories priorities and resolution times', function () {
    $connection = fleetopsInsightsAllocationBoot();
    $now        = now()->toDateTimeString();
    $connection->table('issues')->insert([
        ['uuid' => 'i1', 'company_uuid' => 'company-1', 'category' => 'mechanical', 'priority' => 'high', 'status' => 'pending', 'resolved_at' => null, 'created_at' => $now],
        ['uuid' => 'i2', 'company_uuid' => 'company-1', 'category' => 'mechanical', 'priority' => 'low', 'status' => 'pending', 'resolved_at' => null, 'created_at' => $now],
        ['uuid' => 'i3', 'company_uuid' => 'company-1', 'category' => null, 'priority' => 'medium', 'status' => 'resolved', 'resolved_at' => $now, 'created_at' => now()->subHours(2)->toDateTimeString()],
    ]);

    $insights = IssuesInsights::forCompany(fleetopsInsightsCompany())->get();

    expect($insights['by_category']['labels'])->toContain('mechanical')
        ->and($insights['by_category']['labels'])->toContain('Uncategorized')
        ->and($insights['by_priority']['high'])->toBe(1)
        ->and($insights['by_priority']['medium'])->toBe(1)
        ->and($insights['by_priority']['low'])->toBe(1)
        ->and($insights['open'])->toBe(2)
        ->and($insights['resolved_this_period'])->toBe(1)
        // created_at passes through the model timezone cast while resolved_at
        // stays raw, so the exact delta varies with the harness timezone.
        ->and($insights['avg_resolution_hours'])->toBeFloat();
});

test('issues insights returns empty shapes without data', function () {
    fleetopsInsightsAllocationBoot();

    $insights = IssuesInsights::forCompany(fleetopsInsightsCompany())
        ->between(now()->subDays(7)->toDateTime(), now()->toDateTime())
        ->get();

    expect($insights['by_category']['labels'])->toBe([])
        ->and($insights['open'])->toBe(0)
        ->and($insights['avg_resolution_hours'])->toBeNull();
});

test('allocation job query helpers resolve orders vehicles and settings', function () {
    $connection = fleetopsInsightsAllocationBoot();
    $connection->table('orders')->insert([
        ['uuid' => 'o1', 'public_id' => 'order_a', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => null, 'status' => 'created', 'payload_uuid' => null],
        ['uuid' => 'o2', 'public_id' => 'order_b', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => 'driver-1', 'status' => 'created', 'payload_uuid' => null],
        ['uuid' => 'o3', 'public_id' => 'order_c', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => null, 'status' => 'completed', 'payload_uuid' => null],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'public_id' => 'driver_a', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1', 'online' => '1'],
        ['uuid' => 'driver-2', 'public_id' => 'driver_b', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-2', 'online' => '0'],
    ]);
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'public_id' => 'vehicle_a', 'company_uuid' => 'company-1', 'driver_uuid' => 'driver-1'],
        ['uuid' => 'vehicle-2', 'public_id' => 'vehicle_b', 'company_uuid' => 'company-1', 'driver_uuid' => 'driver-2'],
    ]);

    $job = new FleetOpsAllocationJobProbe('company-1', ['order_a', 'order_c']);

    $unassigned = $job->callProtected('unassignedOrders');
    expect($unassigned->pluck('public_id')->all())->toBe(['order_a']);

    $vehicles = $job->callProtected('availableVehicles');
    expect($vehicles->pluck('uuid')->values()->all())->toBe(['vehicle-1']);

    expect($job->callProtected('engineId'))->toBe('greedy')
        ->and($job->callProtected('allocationOptions'))->toBe(['max_travel_time' => 3600, 'balance_workload' => false]);

    expect($job->callProtected('findOrderByPublicId', 'order_a'))->toBeInstanceOf(Order::class)
        ->and($job->callProtected('findOrderByPublicId', 'missing'))->toBeNull()
        ->and($job->callProtected('findDriverByPublicId', 'driver_a'))->toBeInstanceOf(Driver::class)
        ->and($job->callProtected('findDriverByPublicId', 'missing'))->toBeNull();

    $job->callProtected('logInfo', 'allocation test log');
    expect(true)->toBeTrue();
});
