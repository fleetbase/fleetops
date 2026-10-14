<?php

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Observers\VehicleObserver;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'co-1']);
});

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
});

function vehicleObserverDeleteDatabase(): SQLiteConnection
{
    $db = TrackingPageDatabase::boot();

    $db->getSchemaBuilder()->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $db->getSchemaBuilder()->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    foreach (['user-1', 'user-2', 'user-3'] as $user) {
        TrackingPageDatabase::insert($db, 'users', ['uuid' => $user, 'company_uuid' => 'co-1']);
    }
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-1', 'company_uuid' => 'co-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1']);
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-2', 'company_uuid' => 'co-1', 'user_uuid' => 'user-2', 'vehicle_uuid' => 'vehicle-1']);
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-3', 'company_uuid' => 'co-1', 'user_uuid' => 'user-3', 'vehicle_uuid' => 'vehicle-2']);

    return $db;
}

test('deleting a vehicle unassigns its drivers without deleting them', function () {
    $db = vehicleObserverDeleteDatabase();

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'co-1'], true);

    (new VehicleObserver())->deleted($vehicle);

    $drivers = $db->table('drivers')->orderBy('uuid')->get(['uuid', 'vehicle_uuid', 'deleted_at'])->map(fn ($row) => (array) $row)->all();

    expect($drivers)->toBe([
        ['uuid' => 'driver-1', 'vehicle_uuid' => null, 'deleted_at' => null],
        ['uuid' => 'driver-2', 'vehicle_uuid' => null, 'deleted_at' => null],
        ['uuid' => 'driver-3', 'vehicle_uuid' => 'vehicle-2', 'deleted_at' => null],
    ]);
});
