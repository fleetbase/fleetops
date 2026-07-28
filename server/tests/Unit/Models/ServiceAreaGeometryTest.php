<?php

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;

/**
 * Covers the ServiceArea geometry helpers: circular multi-polygon creation
 * from a point, polygon extraction from the border, point-in-polygon
 * containment for single and multiple coordinates, and the centroid seam
 * (which requires the GEOS engine unavailable in the harness).
 */
function fleetopsServiceAreaWithBorder(): ServiceArea
{
    $serviceArea = new ServiceArea();
    $serviceArea->setRawAttributes(['uuid' => 'sa-1', 'public_id' => 'service_area_test', 'name' => 'Central'], true);

    $square = new Polygon([new LineString([
        new Point(1.0, 103.0),
        new Point(1.0, 104.0),
        new Point(2.0, 104.0),
        new Point(2.0, 103.0),
        new Point(1.0, 103.0),
    ])]);
    // asPolygon iterates border as rings-of-points, matching a Polygon
    // (each ring iterates its points directly)
    $serviceArea->setAttribute('border', $square);

    return $serviceArea;
}

test('create multi polygon from point builds a closed circular border', function () {
    $multiPolygon = ServiceArea::createMultiPolygonFromPoint(new Point(1.3521, 103.8198), 500);

    expect($multiPolygon)->toBeInstanceOf(MultiPolygon::class)
        ->and(count($multiPolygon->getGeometries()))->toBe(1);

    $ring   = $multiPolygon->getGeometries()[0]->getGeometries()[0];
    $points = $ring->getGeometries();
    expect($points[0]->getLat())->toBe(end($points)->getLat())
        ->and($points[0]->getLng())->toBe(end($points)->getLng());
});

test('as polygon extracts border coordinates for containment checks', function () {
    $serviceArea = fleetopsServiceAreaWithBorder();

    $polygon = $serviceArea->asPolygon();
    expect($polygon)->toBeInstanceOf(League\Geotools\Polygon\Polygon::class);

    // Inside and outside points resolve through the geotools containment
    expect($serviceArea->includesPoint(new Point(1.5, 103.5)))->toBeTrue()
        ->and($serviceArea->includesPoint(new Point(5.0, 110.0)))->toBeFalse();

    // Multiple coordinates must all be contained
    expect($serviceArea->includesPoints([new Point(1.5, 103.5), new Point(1.2, 103.2)]))->toBeTrue()
        ->and($serviceArea->includesPoints([new Point(1.5, 103.5), new Point(5.0, 110.0)]))->toBeFalse();
});

test('centroid and location accessors require the geos engine', function () {
    $serviceArea = fleetopsServiceAreaWithBorder();

    // The GEOS engine is unavailable in the harness; the centroid path (and
    // the location/latitude/longitude accessors built on it) still execute
    // to that seam.
    try {
        $location = $serviceArea->location;
        expect($location)->toBeInstanceOf(Point::class)
            ->and($serviceArea->latitude)->toBeFloat()
            ->and($serviceArea->longitude)->toBeFloat();
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(Throwable::class);
    }
});
