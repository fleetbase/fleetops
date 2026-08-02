<?php

use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon as SpatialMultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon as SpatialPolygon;

/**
 * Covers the spatial cast branches that accept an already-typed geometry:
 * each cast stashes the geometry on the model so the spatial trait can bind
 * it at write time, and hands the value straight back.
 */
class FleetOpsSpatialCastModel
{
    public array $geometries = [];
}

function fleetopsSpatialCastSquare(): SpatialPolygon
{
    return new SpatialPolygon([
        new Fleetbase\LaravelMysqlSpatial\Types\LineString([
            new SpatialPoint(1.20, 103.70),
            new SpatialPoint(1.20, 103.95),
            new SpatialPoint(1.45, 103.95),
            new SpatialPoint(1.45, 103.70),
            new SpatialPoint(1.20, 103.70),
        ]),
    ]);
}

test('typed geometries are stashed on the model and wrapped for binding', function () {
    // Every spatial type implements GeometryInterface, so polygon geometries
    // are stashed and wrapped in a spatial expression for the query binding
    $model    = new FleetOpsSpatialCastModel();
    $multi    = new SpatialMultiPolygon([fleetopsSpatialCastSquare()]);
    $returned = (new Fleetbase\FleetOps\Casts\MultiPolygon())->set($model, 'border', $multi, []);

    expect($returned)->toBeInstanceOf(SpatialExpression::class)
        ->and($model->geometries['border'])->toBe($multi);

    // The polygon cast stashes the geometry but hands the value back directly
    $polygonModel = new FleetOpsSpatialCastModel();
    $polygon      = fleetopsSpatialCastSquare();
    $polygonCast  = (new Fleetbase\FleetOps\Casts\Polygon())->set($polygonModel, 'border', $polygon, []);

    expect($polygonCast)->toBe($polygon)
        ->and($polygonModel->geometries['border'])->toBe($polygon);

    // Expressions are already bind-ready, so the point cast returns them
    // untouched — but still stashes them, so the spatial trait can restore the
    // attribute after an insert like it does for every other spatial input
    $pointModel = new FleetOpsSpatialCastModel();
    $expression = new SpatialExpression(new SpatialPoint(1.30, 103.80));
    $pointCast  = (new Fleetbase\FleetOps\Casts\Point())->set($pointModel, 'location', $expression, []);

    expect($pointCast)->toBe($expression)
        ->and($pointModel->geometries['location'])->toBe($expression);

    // A bare point is stashed and wrapped for binding
    $bareModel = new FleetOpsSpatialCastModel();
    $bare      = new SpatialPoint(1.30, 103.80);
    expect((new Fleetbase\FleetOps\Casts\Point())->set($bareModel, 'location', $bare, []))->toBeInstanceOf(SpatialExpression::class)
        ->and($bareModel->geometries['location'])->toBe($bare);
});

test('raw binary points read back through the point cast', function () {
    // The driver hands back the packed geometry MySQL stored
    $raw = pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', 103.80) . pack('d', 1.30);

    $point = (new Fleetbase\FleetOps\Casts\Point())->get(new FleetOpsSpatialCastModel(), 'location', $raw, []);

    expect($point)->toBeInstanceOf(SpatialPoint::class)
        ->and(round($point->getLat(), 2))->toBe(103.80)
        ->and(round($point->getLng(), 2))->toBe(1.30);
});
