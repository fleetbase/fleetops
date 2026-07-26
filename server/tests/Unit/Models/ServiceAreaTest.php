<?php

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use League\Geotools\Polygon\MultiPolygon as GeotoolsMultiPolygon;

function fleetopsServiceAreaUnitUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsServiceAreaUnitBorder(): MultiPolygon
{
    return new MultiPolygon([
        new Polygon([
            new LineString([
                new Point(1.30, 103.80),
                new Point(1.30, 103.90),
                new Point(1.40, 103.90),
                new Point(1.40, 103.80),
                new Point(1.30, 103.80),
            ]),
        ]),
    ]);
}

test('service area exposes zone relation metadata and default mutators', function () {
    fleetopsServiceAreaUnitUseInMemoryRelationConnection();

    $area  = new ServiceArea(['status' => null, 'type' => null]);
    $zones = $area->zones();

    expect($zones->getRelated())->toBeInstanceOf(Zone::class)
        ->and($zones->getForeignKeyName())->toBe('service_area_uuid')
        ->and($zones->getLocalKeyName())->toBe('uuid')
        ->and($area->status)->toBeNull()
        ->and($area->type)->toBeNull();
});

test('service area creates closed spatial polygons around center points', function () {
    $point = new Point(1.35, 103.85);

    $polygon      = ServiceArea::createPolygonFromPoint($point, 250);
    $multiPolygon = ServiceArea::createMultiPolygonFromPoint($point, 250);

    $lineString = $multiPolygon->getPolygons()[0]->getLineStrings()[0];
    $points     = $lineString->getPoints();

    expect($polygon)->toBeInstanceOf(Polygon::class)
        ->and($multiPolygon)->toBeInstanceOf(MultiPolygon::class)
        ->and($points)->not->toBeEmpty()
        ->and((string) $points[0])->toBe((string) $points[array_key_last($points)]);
});

test('service area converts populated borders into geotools and geos shapes', function () {
    $area = new ServiceArea();
    $area->setRawAttributes(['border' => fleetopsServiceAreaUnitBorder()], true);

    $multiPolygon = $area->asMultiPolygon();
    $coordinates  = $area->toGeosCoordinates();
    $lineStrings  = $area->toGeosLineStrings();
    $polygon      = $area->toGeosPolygon();
    $geosMulti    = $area->toGeosMultiPolygon();

    expect($multiPolygon)->toBeInstanceOf(GeotoolsMultiPolygon::class)
        ->and($coordinates)->toHaveCount(5)
        ->and((string) $coordinates[0])->toBe('POINT (1.3 103.8)')
        ->and($lineStrings)->toHaveCount(1)
        ->and($polygon)->toBeInstanceOf(Brick\Geo\Polygon::class)
        ->and($geosMulti)->toBeInstanceOf(Brick\Geo\MultiPolygon::class);
});
