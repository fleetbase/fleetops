<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\VehicleDevice;
use Fleetbase\FleetOps\Models\VehicleDeviceEvent;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;

class FleetOpsVehicleDeviceModelDatabaseProbe
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

class FleetOpsVehicleDeviceModelProbe extends VehicleDevice
{
    public array $loaded = [];

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }
}

function fleetopsVehicleDeviceModelUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table vehicles (
        id integer primary key autoincrement,
        uuid varchar(64),
        public_id varchar(64) null,
        name varchar(64) null,
        deleted_at datetime null
    )');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsVehicleDeviceModelDatabaseProbe($connection));

    return $connection;
}

test('vehicle device relationships point to vehicles and device events', function () {
    fleetopsVehicleDeviceModelUseInMemoryConnection();

    $device = new VehicleDevice();

    expect($device->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($device->vehicle()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($device->events())->toBeInstanceOf(HasMany::class)
        ->and($device->events()->getRelated())->toBeInstanceOf(VehicleDeviceEvent::class);
});

test('vehicle device resolves loaded vehicles persisted vehicles and callback hooks', function () {
    $connection = fleetopsVehicleDeviceModelUseInMemoryConnection();
    $connection->table('vehicles')->insert([
        'uuid'      => 'vehicle-uuid',
        'public_id' => 'vehicle_public',
        'name'      => 'Truck 9',
    ]);

    $loadedVehicle = new Vehicle();
    $loadedVehicle->setRawAttributes(['uuid' => 'loaded-vehicle'], true);

    $loadedDevice = new FleetOpsVehicleDeviceModelProbe();
    $loadedDevice->setRelation('vehicle', $loadedVehicle);

    $callbackVehicle = null;
    expect($loadedDevice->getVehicle(function (Vehicle $vehicle) use (&$callbackVehicle): void {
        $callbackVehicle = $vehicle;
    }))->toBe($loadedVehicle)
        ->and($callbackVehicle)->toBe($loadedVehicle)
        ->and($loadedDevice->loaded)->toBe([['vehicle']]);

    $queriedDevice = new FleetOpsVehicleDeviceModelProbe();
    $queriedDevice->setRawAttributes(['vehicle_uuid' => 'vehicle-uuid'], true);

    $queriedVehicle = $queriedDevice->getVehicle(null);

    expect($queriedVehicle)->toBeInstanceOf(Vehicle::class)
        ->and($queriedVehicle->uuid)->toBe('vehicle-uuid')
        ->and($queriedVehicle->name)->toBe('Truck 9');

    $missingDevice = new FleetOpsVehicleDeviceModelProbe();
    expect($missingDevice->getVehicle(null))->toBeNull();
});
