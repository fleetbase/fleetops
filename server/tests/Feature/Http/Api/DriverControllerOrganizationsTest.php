<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\Http\Resources\Organization;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the API DriverController organization endpoints against SQLite:
 * resolving the driver's current organization through the company session
 * fallback chain, the missing-user and missing-company error branches, and
 * listing all organizations the driver's user account belongs to.
 */
const FLEETOPS_DRIVER_ORG_USER      = '11111111-1111-4111-8111-111111111111';
const FLEETOPS_DRIVER_ORG_COMPANY_1 = '22222222-2222-4222-8222-222222222222';
const FLEETOPS_DRIVER_ORG_COMPANY_2 = '33333333-3333-4333-8333-333333333333';

function fleetopsDriverOrgBoot(): SQLiteConnection
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
    app()->instance('db.schema', $schema);
    $tables = [
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type'],
        'drivers'       => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location'],
        'companies'     => ['uuid', 'public_id', 'name', 'options', 'status', 'owner_uuid'],
        'company_users' => ['uuid', 'company_uuid', 'user_uuid', 'status'],
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

    session(['company' => FLEETOPS_DRIVER_ORG_COMPANY_1]);

    $connection->table('users')->insert(['uuid' => FLEETOPS_DRIVER_ORG_USER, 'company_uuid' => FLEETOPS_DRIVER_ORG_COMPANY_1, 'name' => 'Driver One']);
    $connection->table('companies')->insert([
        ['uuid' => FLEETOPS_DRIVER_ORG_COMPANY_1, 'public_id' => 'company_one', 'name' => 'One'],
        ['uuid' => FLEETOPS_DRIVER_ORG_COMPANY_2, 'public_id' => 'company_two', 'name' => 'Two'],
    ]);
    $connection->table('company_users')->insert([
        ['uuid' => 'cu-1', 'company_uuid' => FLEETOPS_DRIVER_ORG_COMPANY_1, 'user_uuid' => FLEETOPS_DRIVER_ORG_USER],
        ['uuid' => 'cu-2', 'company_uuid' => FLEETOPS_DRIVER_ORG_COMPANY_2, 'user_uuid' => FLEETOPS_DRIVER_ORG_USER],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => FLEETOPS_DRIVER_ORG_COMPANY_1, 'user_uuid' => FLEETOPS_DRIVER_ORG_USER],
        ['uuid' => 'driver-2', 'public_id' => 'driver_two', 'company_uuid' => FLEETOPS_DRIVER_ORG_COMPANY_2, 'user_uuid' => FLEETOPS_DRIVER_ORG_USER],
    ]);

    return $connection;
}

test('current organization resolves the company for the driver user', function () {
    fleetopsDriverOrgBoot();

    $organization = (new DriverController())->currentOrganization('driver_one', Request::create('/x', 'GET'));

    expect($organization)->toBeInstanceOf(Organization::class)
        ->and($organization->uuid)->toBe(FLEETOPS_DRIVER_ORG_COMPANY_1);
});

test('current organization errors when the company cannot be resolved', function () {
    $connection = fleetopsDriverOrgBoot();

    // User whose company cannot be resolved through the session fallback
    // chain: non-uuid company reference and no company_users memberships.
    // (A missing user row is unreachable here — the driver global scope
    // already excludes drivers without an existing user.)
    $connection->table('users')->where('uuid', FLEETOPS_DRIVER_ORG_USER)->update(['company_uuid' => 'not-a-uuid']);
    $connection->table('company_users')->delete();
    $noCompany = (new DriverController())->currentOrganization('driver_one', Request::create('/x', 'GET'));
    expect($noCompany)->toBeInstanceOf(JsonResponse::class)
        ->and($noCompany->getData(true)['error'])->toContain('No company found');
});

test('list organizations returns every company the driver user belongs to', function () {
    fleetopsDriverOrgBoot();

    $organizations = (new DriverController())->listOrganizations('driver_one', Request::create('/x', 'GET'));

    expect($organizations->count())->toBe(2)
        ->and($organizations->collection->pluck('public_id')->all())->toContain('company_one', 'company_two');
});
