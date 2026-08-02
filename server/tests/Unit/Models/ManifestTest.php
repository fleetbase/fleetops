<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;

class FleetOpsManifestModelProbe extends Manifest
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

function fleetopsManifestUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table manifest_stops (
        id integer primary key autoincrement,
        manifest_uuid varchar(64),
        status varchar(32),
        sequence integer null,
        deleted_at datetime null
    )');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    return $connection;
}

test('manifest relationship contracts resolve expected models', function () {
    fleetopsManifestUseInMemoryConnection();

    $manifest = new Manifest();

    expect($manifest->driver())->toBeInstanceOf(BelongsTo::class)
        ->and($manifest->driver()->getRelated())->toBeInstanceOf(Driver::class)
        ->and($manifest->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($manifest->vehicle()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($manifest->stops())->toBeInstanceOf(HasMany::class)
        ->and($manifest->stops()->getRelated())->toBeInstanceOf(ManifestStop::class)
        ->and($manifest->orders())->toBeInstanceOf(HasMany::class)
        ->and($manifest->orders()->getRelated())->toBeInstanceOf(Order::class);
});

test('manifest exposes driver vehicle and stop count accessors', function () {
    $connection = fleetopsManifestUseInMemoryConnection();
    $connection->table('manifest_stops')->insert([
        ['manifest_uuid' => 'manifest-uuid', 'status' => 'completed', 'sequence' => 1],
        ['manifest_uuid' => 'manifest-uuid', 'status' => 'pending', 'sequence' => 2],
        ['manifest_uuid' => 'manifest-uuid', 'status' => 'arrived', 'sequence' => 3],
        ['manifest_uuid' => 'manifest-uuid', 'status' => 'skipped', 'sequence' => 4],
    ]);

    $driver = new Driver();
    $driver->setRelation('user', (object) ['name' => 'Dispatch Driver']);

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['name' => 'Truck 12'], true);

    $manifest = new Manifest();
    $manifest->setRawAttributes(['uuid' => 'manifest-uuid'], true);
    $manifest->setRelation('driver', $driver);
    $manifest->setRelation('vehicle', $vehicle);

    expect($manifest->driver_name)->toBe('Dispatch Driver')
        ->and($manifest->vehicle_name)->toBe('Truck 12')
        ->and($manifest->completed_stops)->toBe(1)
        ->and($manifest->pending_stops)->toBe(2);
});

test('manifest scopes apply company driver vehicle and active status filters', function () {
    fleetopsManifestUseInMemoryConnection();

    $companyQuery = Manifest::query();
    (new Manifest())->scopeForCompany($companyQuery, 'company-uuid');

    $driverQuery = Manifest::query();
    (new Manifest())->scopeForDriver($driverQuery, 'driver-uuid');

    $vehicleQuery = Manifest::query();
    (new Manifest())->scopeForVehicle($vehicleQuery, 'vehicle-uuid');

    $activeQuery = Manifest::query();
    (new Manifest())->scopeActive($activeQuery);

    expect($companyQuery->getQuery()->wheres[0])->toMatchArray(['column' => 'company_uuid', 'value' => 'company-uuid'])
        ->and($driverQuery->getQuery()->wheres[0])->toMatchArray(['column' => 'driver_uuid', 'value' => 'driver-uuid'])
        ->and($vehicleQuery->getQuery()->wheres[0])->toMatchArray(['column' => 'vehicle_uuid', 'value' => 'vehicle-uuid'])
        ->and($activeQuery->getQuery()->wheres[0]['type'])->toBe('In')
        ->and($activeQuery->getQuery()->wheres[0]['column'])->toBe('status')
        ->and($activeQuery->getQuery()->wheres[0]['values'])->toBe(['active', 'in_progress']);
});

test('manifest lifecycle helpers update state and auto-complete only when no pending stops remain', function () {
    $connection = fleetopsManifestUseInMemoryConnection();
    Carbon::setTestNow(Carbon::parse('2026-08-05 13:45:00'));

    $manifest = new FleetOpsManifestModelProbe();
    $manifest->setRawAttributes(['uuid' => 'manifest-uuid', 'status' => 'active'], true);

    expect($manifest->start())->toBe($manifest)
        ->and($manifest->updates[0]['status'])->toBe('in_progress')
        ->and($manifest->updates[0]['started_at']->toDateTimeString())->toBe('2026-08-05 13:45:00');

    expect($manifest->cancel())->toBe($manifest)
        ->and($manifest->updates[1])->toBe(['status' => 'cancelled']);

    expect($manifest->complete())->toBe($manifest)
        ->and($manifest->updates[2]['status'])->toBe('completed')
        ->and($manifest->updates[2]['completed_at']->toDateTimeString())->toBe('2026-08-05 13:45:00');

    $pendingManifest = new FleetOpsManifestModelProbe();
    $pendingManifest->setRawAttributes(['uuid' => 'pending-manifest', 'status' => 'in_progress'], true);
    $connection->table('manifest_stops')->insert([
        ['manifest_uuid' => 'pending-manifest', 'status' => 'pending'],
    ]);

    $pendingManifest->checkAndAutoComplete();

    expect($pendingManifest->updates)->toBe([]);

    $readyManifest = new FleetOpsManifestModelProbe();
    $readyManifest->setRawAttributes(['uuid' => 'ready-manifest', 'status' => 'in_progress'], true);
    $connection->table('manifest_stops')->insert([
        ['manifest_uuid' => 'ready-manifest', 'status' => 'completed'],
        ['manifest_uuid' => 'ready-manifest', 'status' => 'skipped'],
    ]);

    $readyManifest->checkAndAutoComplete();

    expect($readyManifest->updates[0]['status'])->toBe('completed')
        ->and($readyManifest->updates[0]['completed_at']->toDateTimeString())->toBe('2026-08-05 13:45:00');

    Carbon::setTestNow();
});
