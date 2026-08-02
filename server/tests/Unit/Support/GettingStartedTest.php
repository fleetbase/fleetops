<?php

use Fleetbase\FleetOps\Support\GettingStarted;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsGettingStartedUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsGettingStartedUnitUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table users (uuid varchar(64), deleted_at datetime null)');
    $connection->statement('create table drivers (uuid varchar(64), company_uuid varchar(64), user_uuid varchar(64), deleted_at datetime null)');
    $connection->statement('create table orders (uuid varchar(64), company_uuid varchar(64), driver_assigned_uuid varchar(64) null, deleted_at datetime null)');
    $connection->statement('create table tracking_statuses (uuid varchar(64), company_uuid varchar(64), tracking_number_uuid varchar(64) null, code varchar(64) null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsGettingStartedUnitDatabaseProbe($connection));

    return $connection;
}

function fleetopsGettingStartedUnitCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-onboarding'], true);

    return $company;
}

test('getting started checklist reports empty onboarding state', function () {
    fleetopsGettingStartedUnitUseInMemoryConnection();

    $status = GettingStarted::forCompany(fleetopsGettingStartedUnitCompany())->get();

    expect($status)->toMatchArray([
        'profile_source' => 'generic',
        'profile'        => null,
        'is_completed'   => false,
        'progress'       => [
            'completed' => 0,
            'total'     => 4,
            'percent'   => 0,
        ],
        'next_step' => 'add_driver',
    ])
        ->and(array_column($status['steps'], 'key'))->toBe([
            'add_driver',
            'create_order',
            'assign_driver',
            'update_activity',
        ])
        ->and(array_column($status['steps'], 'completed'))->toBe([false, false, false, false])
        ->and($status['steps'][0])->toMatchArray([
            'title'    => 'Add a driver',
            'estimate' => '2 min',
            'icon'     => 'id-card',
            'route'    => 'console.fleet-ops.management.drivers.index.new',
        ])
        ->and(array_column($status['recommendations'], 'key'))->toBe([
            'route_optimization',
            'live_fleet',
            'service_rates',
            'customer_portal',
        ]);
});

test('getting started checklist tracks partial fleet setup', function () {
    $connection = fleetopsGettingStartedUnitUseInMemoryConnection();
    $connection->table('users')->insert([
        'uuid'       => 'user-uuid',
        'deleted_at' => null,
    ]);
    $connection->table('drivers')->insert([
        'uuid'         => 'driver-uuid',
        'company_uuid' => 'company-onboarding',
        'user_uuid'    => 'user-uuid',
        'deleted_at'   => null,
    ]);
    $connection->table('orders')->insert([
        'uuid'                 => 'order-uuid',
        'company_uuid'         => 'company-onboarding',
        'driver_assigned_uuid' => null,
        'deleted_at'           => null,
    ]);
    $connection->table('tracking_statuses')->insert([
        'uuid'                 => 'tracking-created',
        'company_uuid'         => 'company-onboarding',
        'tracking_number_uuid' => 'tracking-number',
        'code'                 => 'ORDER_CREATED',
        'deleted_at'           => null,
    ]);

    $status = GettingStarted::forCompany(fleetopsGettingStartedUnitCompany())->get();

    expect($status['progress'])->toBe([
        'completed' => 2,
        'total'     => 4,
        'percent'   => 50,
    ])
        ->and($status['next_step'])->toBe('assign_driver')
        ->and(array_column($status['steps'], 'completed'))->toBe([true, true, false, false]);
});

test('getting started checklist reports completion after assignment and activity', function () {
    $connection = fleetopsGettingStartedUnitUseInMemoryConnection();
    $connection->table('users')->insert([
        'uuid'       => 'user-uuid',
        'deleted_at' => null,
    ]);
    $connection->table('drivers')->insert([
        'uuid'         => 'driver-uuid',
        'company_uuid' => 'company-onboarding',
        'user_uuid'    => 'user-uuid',
        'deleted_at'   => null,
    ]);
    $connection->table('orders')->insert([
        'uuid'                 => 'order-uuid',
        'company_uuid'         => 'company-onboarding',
        'driver_assigned_uuid' => 'driver-uuid',
        'deleted_at'           => null,
    ]);
    $connection->table('tracking_statuses')->insert([
        'uuid'                 => 'tracking-in-transit',
        'company_uuid'         => 'company-onboarding',
        'tracking_number_uuid' => 'tracking-number',
        'code'                 => 'IN_TRANSIT',
        'deleted_at'           => null,
    ]);

    $status = GettingStarted::forCompany(fleetopsGettingStartedUnitCompany())->get();

    expect($status)->toMatchArray([
        'is_completed' => true,
        'progress'     => [
            'completed' => 4,
            'total'     => 4,
            'percent'   => 100,
        ],
        'next_step' => null,
    ])
        ->and(array_column($status['steps'], 'completed'))->toBe([true, true, true, true])
        ->and($status['recommendations'][1])->toMatchArray([
            'title'  => 'Live Fleet Map',
            'accent' => 'green',
        ]);
});
