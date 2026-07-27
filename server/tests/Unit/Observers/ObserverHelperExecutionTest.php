<?php

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Observers\CategoryObserver;
use Fleetbase\FleetOps\Observers\CompanyObserver;
use Fleetbase\FleetOps\Observers\UserObserver;
use Fleetbase\FleetOps\Observers\ZoneObserver;
use Fleetbase\Models\Company;
use Fleetbase\Models\CustomField;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsObserverHelperDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsObserverHelperConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table custom_fields (uuid varchar(64) primary key, category_uuid varchar(64) null, company_uuid varchar(64) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table users (uuid varchar(64) primary key, company_uuid varchar(64) null, public_id varchar(64) null, avatar_uuid varchar(64) null, name varchar(255) null, phone varchar(64) null, email varchar(255) null, type varchar(64) null, status varchar(64) null, last_login datetime null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table drivers (uuid varchar(64) primary key, user_uuid varchar(64) null, company_uuid varchar(64) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table order_configs (id integer primary key autoincrement, uuid varchar(64) null, public_id varchar(64) null, company_uuid varchar(64) null, author_uuid varchar(64) null, category_uuid varchar(64) null, icon_uuid varchar(64) null, name varchar(255) null, namespace varchar(255) null, description text null, key varchar(255) null, status varchar(64) null, version varchar(64) null, core_service tinyint null, flow text null, entities text null, tags text null, meta text null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsObserverHelperDatabaseProbe($connection));

    return $connection;
}

function fleetopsCallObserverHelper(object $observer, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($observer, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($observer, ...$arguments);
}

test('category observer deletes custom fields for the removed category', function () {
    $connection = fleetopsObserverHelperConnection();
    $connection->table('custom_fields')->insert([
        ['uuid' => 'field-matching-a', 'category_uuid' => 'category-uuid', 'company_uuid' => 'company-uuid'],
        ['uuid' => 'field-matching-b', 'category_uuid' => 'category-uuid', 'company_uuid' => 'company-uuid'],
        ['uuid' => 'field-other', 'category_uuid' => 'other-category', 'company_uuid' => 'company-uuid'],
    ]);

    fleetopsCallObserverHelper(new CategoryObserver(), 'deleteCustomFields', 'category-uuid');

    expect(CustomField::withTrashed()->where('category_uuid', 'category-uuid')->whereNotNull('deleted_at')->count())->toBe(2)
        ->and(CustomField::withTrashed()->where('category_uuid', 'other-category')->whereNull('deleted_at')->count())->toBe(1);
});

test('user observer deletes scoped driver records for the removed user', function () {
    $connection = fleetopsObserverHelperConnection();
    $connection->table('users')->insert([
        ['uuid' => 'user-uuid', 'name' => 'Removed Driver'],
        ['uuid' => 'other-user', 'name' => 'Active Driver'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-matching-a', 'user_uuid' => 'user-uuid', 'company_uuid' => 'company-uuid'],
        ['uuid' => 'driver-matching-b', 'user_uuid' => 'user-uuid', 'company_uuid' => 'company-uuid'],
        ['uuid' => 'driver-other', 'user_uuid' => 'other-user', 'company_uuid' => 'company-uuid'],
    ]);

    fleetopsCallObserverHelper(new UserObserver(), 'deleteDrivers', 'user-uuid');

    expect(Driver::withTrashed()->withoutGlobalScopes()->where('user_uuid', 'user-uuid')->whereNotNull('deleted_at')->count())->toBe(2)
        ->and(Driver::withTrashed()->withoutGlobalScopes()->where('user_uuid', 'other-user')->whereNull('deleted_at')->count())->toBe(1);
});

test('company observer creates the default transport order config', function () {
    fleetopsObserverHelperConnection();

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-uuid'], true);

    (new CompanyObserver())->created($company);

    $transport = OrderConfig::where([
        'company_uuid' => 'company-uuid',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
    ])->first();

    expect($transport)->toBeInstanceOf(OrderConfig::class)
        ->and($transport->name)->toBe('Transport')
        ->and($transport->core_service)->toBe(1)
        ->and($transport->tags)->toBe(['transport', 'delivery'])
        ->and($transport->flow)->toHaveKeys(['created', 'enroute', 'started', 'completed', 'dispatched']);
});

test('zone observer invalidates service area cache only when a service area is present', function () {
    config()->set('api.cache.enabled', false);

    $missingServiceArea = new Zone();
    $missingServiceArea->setRawAttributes([
        'uuid'              => 'zone-without-service-area',
        'company_uuid'      => 'company-uuid',
        'service_area_uuid' => null,
    ], true);

    fleetopsCallObserverHelper(new ZoneObserver(), 'invalidateServiceAreaCache', $missingServiceArea);

    $zone = new Zone();
    $zone->setRawAttributes([
        'uuid'              => 'zone-uuid',
        'company_uuid'      => 'company-uuid',
        'service_area_uuid' => 'service-area-uuid',
    ], true);

    fleetopsCallObserverHelper(new ZoneObserver(), 'invalidateServiceAreaCache', $zone);
    fleetopsCallObserverHelper(new ZoneObserver(), 'invalidateServiceAreaCache', $zone, 'original-service-area-uuid');

    expect(true)->toBeTrue();
});
