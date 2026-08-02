<?php

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Support\Analytics\LiveFleet;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsLiveFleetDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(string $value)
    {
        return $this->connection->raw($value);
    }
}

function fleetopsLiveFleetUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table users (uuid varchar(64) primary key, company_uuid varchar(64) null, public_id varchar(64) null, avatar_uuid varchar(64) null, name varchar(255) null, phone varchar(64) null, email varchar(255) null, type varchar(64) null, status varchar(64) null, last_login datetime null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table drivers (uuid varchar(64) primary key, public_id varchar(64) null, user_uuid varchar(64) null, company_uuid varchar(64) null, vehicle_uuid varchar(64) null, current_job_uuid varchar(64) null, avatar_url varchar(255) null, location blob null, heading numeric null, online integer null, last_location_update_at datetime null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table vehicles (uuid varchar(64) primary key, public_id varchar(64) null, company_uuid varchar(64) null, driver_uuid varchar(64) null, photo_uuid varchar(64) null, avatar_url varchar(255) null, name varchar(255) null, year integer null, make varchar(255) null, model varchar(255) null, trim varchar(255) null, plate_number varchar(64) null, location blob null, heading numeric null, online integer null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table orders (uuid varchar(64) primary key, public_id varchar(64) null, internal_id varchar(64) null, company_uuid varchar(64) null, driver_assigned_uuid varchar(64) null, status varchar(64) null, tracking_number_uuid varchar(64) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table files (uuid varchar(64) primary key, type varchar(64) null, url varchar(255) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsLiveFleetDatabaseProbe($connection));

    return $connection;
}

function fleetopsLiveFleetCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-live-fleet'], true);

    return $company;
}

function fleetopsLiveFleetRawPoint(float $lat, float $lng): string
{
    return pack('lCldd', 0, 1, 1, $lng, $lat);
}

test('live fleet get returns active driver vehicle and order map payloads', function () {
    $connection = fleetopsLiveFleetUseInMemoryConnection();
    $connection->table('users')->insert([
        [
            'uuid'         => 'user-driver-active',
            'company_uuid' => 'company-live-fleet',
            'name'         => 'Ada Driver',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'user-driver-offline',
            'company_uuid' => 'company-live-fleet',
            'name'         => 'Ignored Driver',
            'deleted_at'   => null,
        ],
    ]);
    $connection->table('drivers')->insert([
        [
            'uuid'                    => 'driver-active',
            'public_id'               => 'driver_public',
            'user_uuid'               => 'user-driver-active',
            'company_uuid'            => 'company-live-fleet',
            'current_job_uuid'        => 'order-active',
            'avatar_url'              => 'https://example.test/driver.png',
            'location'                => fleetopsLiveFleetRawPoint(1.3, 103.8),
            'heading'                 => 93.5,
            'online'                  => 1,
            'last_location_update_at' => '2026-07-27 10:15:00',
            'deleted_at'              => null,
        ],
        [
            'uuid'                    => 'driver-offline-no-job',
            'public_id'               => 'driver_ignored',
            'user_uuid'               => 'user-driver-offline',
            'company_uuid'            => 'company-live-fleet',
            'current_job_uuid'        => null,
            'avatar_url'              => null,
            'location'                => fleetopsLiveFleetRawPoint(1.4, 103.9),
            'heading'                 => 0,
            'online'                  => 0,
            'last_location_update_at' => '2026-07-27 10:20:00',
            'deleted_at'              => null,
        ],
        [
            'uuid'                    => 'driver-other-company',
            'public_id'               => 'driver_other',
            'user_uuid'               => 'user-driver-active',
            'company_uuid'            => 'other-company',
            'current_job_uuid'        => 'order-other',
            'avatar_url'              => null,
            'location'                => fleetopsLiveFleetRawPoint(2.4, 104.9),
            'heading'                 => 0,
            'online'                  => 1,
            'last_location_update_at' => '2026-07-27 10:25:00',
            'deleted_at'              => null,
        ],
    ]);
    $connection->table('vehicles')->insert([
        [
            'uuid'         => 'vehicle-active',
            'public_id'    => 'vehicle_public',
            'company_uuid' => 'company-live-fleet',
            'avatar_url'   => 'https://example.test/vehicle.png',
            'name'         => 'Van 7',
            'plate_number' => 'SBA1234Z',
            'location'     => fleetopsLiveFleetRawPoint(1.31, 103.81),
            'heading'      => 45,
            'online'       => 1,
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'vehicle-no-location',
            'public_id'    => 'vehicle_ignored',
            'company_uuid' => 'company-live-fleet',
            'avatar_url'   => null,
            'name'         => 'No Location',
            'plate_number' => null,
            'location'     => null,
            'heading'      => null,
            'online'       => 1,
            'deleted_at'   => null,
        ],
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'                 => 'order-active',
            'public_id'            => 'order_public',
            'company_uuid'         => 'company-live-fleet',
            'driver_assigned_uuid' => 'driver-active',
            'status'               => 'started',
            'tracking_number_uuid' => 'tracking-uuid',
            'deleted_at'           => null,
        ],
        [
            'uuid'                 => 'order-completed',
            'public_id'            => 'order_done',
            'company_uuid'         => 'company-live-fleet',
            'driver_assigned_uuid' => 'driver-active',
            'status'               => 'completed',
            'tracking_number_uuid' => 'tracking-done',
            'deleted_at'           => null,
        ],
    ]);

    $result = LiveFleet::forCompany(fleetopsLiveFleetCompany())->get();

    expect($result['drivers'])->toHaveCount(1)
        ->and($result['drivers'][0])->toMatchArray([
            'uuid'               => 'driver-active',
            'public_id'          => 'driver_public',
            'name'               => 'Ada Driver',
            'avatar_url'         => 'https://example.test/driver.png',
            'online'             => true,
            'heading'            => 93.5,
            'current_order_uuid' => 'order-active',
            'lat'                => 1.3,
            'lng'                => 103.8,
            'updated_at'         => '2026-07-27 10:15:00',
        ])
        ->and($result['vehicles'])->toHaveCount(1)
        ->and($result['vehicles'][0])->toMatchArray([
            'uuid'         => 'vehicle-active',
            'public_id'    => 'vehicle_public',
            'name'         => 'Van 7',
            'plate_number' => 'SBA1234Z',
            'avatar_url'   => 'https://example.test/vehicle.png',
            'online'       => true,
            'heading'      => 45.0,
            'lat'          => 1.31,
            'lng'          => 103.81,
        ])
        ->and($result['active_orders'])->toBe([
            [
                'uuid'        => 'order-active',
                'driver_uuid' => 'driver-active',
                'status'      => 'started',
            ],
        ]);
});
