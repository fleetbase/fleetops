<?php

use Fleetbase\FleetOps\Support\Analytics\OperationsPulse;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers the OperationsPulse analytics snapshot: the tile aggregation
 * queries (active orders, drivers online, vehicles deployed, open issues,
 * completed-today windows) and the delta percentage edge cases.
 */
function fleetopsOperationsPulseBoot(): SQLiteConnection
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
        'orders'   => ['uuid', 'public_id', 'company_uuid', 'status'],
        'drivers'  => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'online', 'current_job_uuid', 'status'],
        'users'    => ['uuid', 'public_id', 'company_uuid'],
        'vehicles' => ['uuid', 'public_id', 'company_uuid', 'status'],
        'issues'   => ['uuid', 'public_id', 'company_uuid', 'status'],
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

function fleetopsOperationsPulseCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme'], true);
    $company->exists = true;

    return $company;
}

test('operations pulse aggregates tile metrics from company data', function () {
    $connection = fleetopsOperationsPulseBoot();
    $now        = Carbon::now()->toDateTimeString();

    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'driver_enroute', 'updated_at' => $now],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1', 'status' => 'completed', 'updated_at' => $now],
        ['uuid' => 'order-3', 'company_uuid' => 'company-1', 'status' => 'completed', 'updated_at' => Carbon::now()->subDays(3)->toDateTimeString()],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => '1', 'current_job_uuid' => 'order-1', 'vehicle_uuid' => 'vehicle-1', 'updated_at' => $now],
        ['uuid' => 'driver-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => '0', 'current_job_uuid' => null, 'vehicle_uuid' => null, 'updated_at' => $now],
    ]);
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'updated_at' => $now],
        ['uuid' => 'vehicle-2', 'company_uuid' => 'company-1', 'updated_at' => $now],
    ]);
    $connection->table('issues')->insert([
        ['uuid' => 'issue-1', 'company_uuid' => 'company-1', 'status' => 'pending', 'updated_at' => $now],
        ['uuid' => 'issue-2', 'company_uuid' => 'company-1', 'status' => 'resolved', 'updated_at' => $now],
    ]);

    $pulse = OperationsPulse::forCompany(fleetopsOperationsPulseCompany())->get();

    expect($pulse['active_orders']['value'])->toBe(1)
        ->and($pulse['completed_today']['value'])->toBe(1)
        // No completions in yesterday's window: delta caps at +100
        ->and($pulse['completed_today']['delta_pct'])->toBe(100.0)
        ->and($pulse['drivers_online']['value'])->toBe(1)
        ->and($pulse['drivers_online']['of'])->toBe(2)
        ->and($pulse['drivers_online']['pct_of_max'])->toBe(50.0)
        ->and($pulse['vehicles_deployed']['of'])->toBe(2)
        ->and($pulse['issues_open']['value'])->toBe(1);
});

test('operations pulse handles empty companies with null deltas', function () {
    fleetopsOperationsPulseBoot();

    $pulse = OperationsPulse::forCompany(fleetopsOperationsPulseCompany())->get();

    expect($pulse['active_orders']['value'])->toBe(0)
        ->and($pulse['completed_today']['value'])->toBe(0)
        ->and($pulse['completed_today']['delta_pct'])->toBeNull()
        ->and($pulse['drivers_online']['pct_of_max'])->toBe(0.0)
        ->and($pulse['vehicles_deployed']['pct_of_max'])->toBe(0.0);
});

test('delta percentage computes signed rounded changes', function () {
    fleetopsOperationsPulseBoot();

    $deltaPct = new ReflectionMethod(OperationsPulse::class, 'deltaPct');
    $deltaPct->setAccessible(true);
    $pulse = OperationsPulse::forCompany(fleetopsOperationsPulseCompany());

    expect($deltaPct->invoke($pulse, 15, 10))->toBe(50.0)
        ->and($deltaPct->invoke($pulse, 5, 10))->toBe(-50.0)
        ->and($deltaPct->invoke($pulse, 0, 0))->toBeNull()
        ->and($deltaPct->invoke($pulse, 3, 0))->toBe(100.0);
});
