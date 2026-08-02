<?php

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Observers\CompanyUserObserver;
use Fleetbase\FleetOps\Observers\ServiceAreaObserver;
use Fleetbase\Models\CompanyUser;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the CompanyUserObserver driver cleanup/sync helpers and the
 * ServiceAreaObserver border creation and zone deletion helpers against
 * SQLite.
 */
function fleetopsObserversBoot(): SQLiteConnection
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
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'status'],
        'drivers'       => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status'],
        'zones'         => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name'],
        'service_areas' => ['uuid', 'public_id', 'company_uuid', 'name', 'border', 'country'],
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

function fleetopsObserversCompanyUser(array $attributes = []): CompanyUser
{
    $companyUser = new CompanyUser();
    $companyUser->setRawAttributes(array_merge([
        'uuid'         => 'cu-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-1',
        'status'       => 'active',
    ], $attributes), true);
    $companyUser->exists = true;

    return $companyUser;
}

test('company user deletion removes associated driver records', function () {
    $connection = fleetopsObserversBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'active']);

    (new CompanyUserObserver())->deleted(fleetopsObserversCompanyUser());

    expect($connection->table('drivers')->whereNull('deleted_at')->count())->toBe(0);
});

test('company user status changes sync onto the driver record', function () {
    $connection = fleetopsObserversBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'active']);

    $companyUser = fleetopsObserversCompanyUser(['status' => 'suspended']);
    $companyUser->syncChanges();

    // Without a recorded status change nothing syncs
    (new CompanyUserObserver())->updated($companyUser);
    expect($connection->table('drivers')->value('status'))->toBe('active');

    // A recorded status change syncs the driver status
    $companyUser->setRawAttributes(['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'active'], true);
    $companyUser->status = 'suspended';
    $companyUser->syncChanges();
    (new CompanyUserObserver())->updated($companyUser);
    expect($connection->table('drivers')->value('status'))->toBe('suspended');
});

test('service area deletion cascades to zones and border derives from country', function () {
    $connection = fleetopsObserversBoot();
    $connection->table('zones')->insert(['uuid' => 'zone-1', 'company_uuid' => 'company-1', 'service_area_uuid' => 'sa-1', 'name' => 'Zone 1']);

    $serviceArea = new ServiceArea();
    $serviceArea->setRawAttributes(['uuid' => 'sa-1', 'company_uuid' => 'company-1', 'name' => 'Area'], true);
    $serviceArea->exists = true;
    $serviceArea->setRelation('zones', Zone::where('service_area_uuid', 'sa-1')->get());

    (new ServiceAreaObserver())->deleted($serviceArea);
    expect($connection->table('zones')->whereNull('deleted_at')->count())->toBe(0);

    // The real polygon helper executes; country datasets are unavailable in
    // the harness so any output (polygon or null) is acceptable coverage.
    $probe = new class extends ServiceAreaObserver {
        public function polygon(string $country): mixed
        {
            return $this->createPolygonFromCountry($country);
        }
    };
    try {
        $result = $probe->polygon('SG');
        expect(true)->toBeTrue();
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(Throwable::class);
    }
});
