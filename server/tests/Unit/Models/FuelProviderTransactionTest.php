<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;

function fleetopsFuelProviderTransactionUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('fuel provider transaction relationship contracts resolve expected models', function () {
    fleetopsFuelProviderTransactionUseRelationConnection();

    $transaction = new FuelProviderTransaction();

    expect($transaction->connection())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->connection()->getRelated())->toBeInstanceOf(FuelProviderConnection::class)
        ->and($transaction->fuelReport())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->fuelReport()->getRelated())->toBeInstanceOf(FuelReport::class)
        ->and($transaction->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->vehicle()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($transaction->driver())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->driver()->getRelated())->toBeInstanceOf(Driver::class)
        ->and($transaction->order())->toBeInstanceOf(BelongsTo::class)
        ->and($transaction->order()->getRelated())->toBeInstanceOf(Order::class);
});

test('fuel provider transaction exposes related display attributes and station point', function () {
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['name' => 'Truck 42'], true);

    $driver = new Driver();
    $driver->setRelation('user', (object) ['name' => 'Jane Driver']);

    $fuelReport = new FuelReport();
    $fuelReport->setRawAttributes(['public_id' => 'fuel_report_001'], true);

    $transaction = new FuelProviderTransaction([
        'station_latitude'  => 1.3521,
        'station_longitude' => 103.8198,
    ]);
    $transaction->setRelation('vehicle', $vehicle);
    $transaction->setRelation('driver', $driver);
    $transaction->setRelation('fuelReport', $fuelReport);

    expect($transaction->vehicle_name)->toBe('Truck 42')
        ->and($transaction->driver_name)->toBe('Jane Driver')
        ->and($transaction->fuel_report_id)->toBe('fuel_report_001')
        ->and($transaction->station_location)->toBeInstanceOf(Point::class)
        ->and($transaction->station_location->getLat())->toBe(1.3521)
        ->and($transaction->station_location->getLng())->toBe(103.8198);
});

test('fuel provider transaction returns null station locations for incomplete coordinates', function () {
    expect((new FuelProviderTransaction())->station_location)->toBeNull()
        ->and((new FuelProviderTransaction(['station_latitude' => 1.3521]))->station_location)->toBeNull()
        ->and((new FuelProviderTransaction(['station_longitude' => 103.8198]))->station_location)->toBeNull();
});
