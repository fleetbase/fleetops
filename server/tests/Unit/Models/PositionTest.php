<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

function fleetopsPositionUnitUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('position relationships use expected owner and destination models', function () {
    fleetopsPositionUnitUseInMemoryRelationConnection();

    $position = new Position();

    expect($position->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($position->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($position->destination()->getRelated())->toBeInstanceOf(Place::class)
        ->and($position->subject()->getMorphType())->toBe('subject_type')
        ->and($position->subject()->getForeignKeyName())->toBe('subject_uuid');
});

test('position latitude and longitude default to zero without coordinates', function () {
    $position = new Position();

    expect($position->latitude)->toBe(0.0)
        ->and($position->longitude)->toBe(0.0);
});

test('position latitude and longitude are read from spatial coordinates', function () {
    $position = new Position();
    $position->setRawAttributes(['coordinates' => new Point(1.3521, 103.8198)], true);

    expect($position->latitude)->toBe(1.3521)
        ->and($position->longitude)->toBe(103.8198);
});
