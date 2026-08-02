<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $default; }');
}

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

function fleetopsZoneUnitUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsZoneUnitBorder(): Polygon
{
    return new Polygon([
        new LineString([
            new Point(1.30, 103.80),
            new Point(1.30, 103.90),
            new Point(1.40, 103.90),
            new Point(1.40, 103.80),
            new Point(1.30, 103.80),
        ]),
    ]);
}

test('zone exposes service area relation metadata and static type', function () {
    fleetopsZoneUnitUseInMemoryRelationConnection();

    $zone        = new Zone();
    $serviceArea = $zone->serviceArea();

    expect($serviceArea->getRelated())->toBeInstanceOf(ServiceArea::class)
        ->and($serviceArea->getForeignKeyName())->toBe('service_area_uuid')
        ->and($serviceArea->getOwnerKeyName())->toBe('uuid')
        ->and($zone->type)->toBe('zone');
});

test('zone returns empty geos shapes when border cannot create a polygon', function () {
    $zone = new Zone();
    $zone->setRawAttributes(['border' => null], true);

    expect($zone->toGeosLineStrings())->toBe([])
        ->and($zone->toGeosPolygon())->toBeNull();
});

test('zone converts populated borders into geos line strings and polygons', function () {
    $zone = new Zone();
    $zone->setRawAttributes(['border' => fleetopsZoneUnitBorder()], true);

    $lineStrings = $zone->toGeosLineStrings();
    $polygon     = $zone->toGeosPolygon();

    expect($lineStrings)->toHaveCount(1)
        ->and((string) $lineStrings[0]->pointN(1))->toBe('POINT (1.3 103.8)')
        ->and($polygon)->toBeInstanceOf(Brick\Geo\Polygon::class);
});

test('zone creates closed spatial polygons around center points', function () {
    $point   = new Point(1.35, 103.85);
    $polygon = Zone::createPolygonFromPoint($point, 250);

    $lineString = $polygon->getLineStrings()[0];
    $points     = $lineString->getPoints();

    expect($polygon)->toBeInstanceOf(Polygon::class)
        ->and($points)->not->toBeEmpty()
        ->and((string) $points[0])->toBe((string) $points[array_key_last($points)]);
});
