<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
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

function driverAssignVehicleDatabase(): SQLiteConnection
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
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    foreach (['user-1', 'user-2'] as $user) {
        TrackingPageDatabase::insert($db, 'users', ['uuid' => $user, 'company_uuid' => 'co-1']);
    }
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-1', 'company_uuid' => 'co-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1']);
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-2', 'company_uuid' => 'co-1', 'user_uuid' => 'user-2']);

    return $db;
}

function driverAssignVehicleVehicle(): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'co-1'], true);
    $vehicle->exists = true;

    return $vehicle;
}

function driverAssignVehicleAssignments(SQLiteConnection $db): array
{
    return $db->table('drivers')->orderBy('uuid')->pluck('vehicle_uuid', 'uuid')->all();
}

test('assigning a driver the vehicle it already has keeps the assignment', function () {
    $db = driverAssignVehicleDatabase();

    Driver::where('uuid', 'driver-1')->first()->assignVehicle(driverAssignVehicleVehicle());

    expect(driverAssignVehicleAssignments($db))->toBe(['driver-1' => 'vehicle-1', 'driver-2' => null]);
});

test('assigning a vehicle to another driver moves it from its current driver', function () {
    $db = driverAssignVehicleDatabase();

    $driver = Driver::where('uuid', 'driver-2')->first()->assignVehicle(driverAssignVehicleVehicle());

    expect(driverAssignVehicleAssignments($db))->toBe(['driver-1' => null, 'driver-2' => 'vehicle-1'])
        ->and($driver->vehicle->uuid)->toBe('vehicle-1');
});
