<?php

if (!function_exists('Fleetbase\FleetOps\Support\resource_path')) {
    eval('namespace Fleetbase\FleetOps\Support; function resource_path($path = "") { return getcwd() . "/server/" . ltrim($path, "/"); }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Fleetbase\Models\Company;
use Illuminate\Database\Eloquent\Model as EloquentModel;

function fleetopsUtilsAdditionalPoint(float $lat = 47.9131423, float $lng = 106.9338169): Point
{
    return new Point($lat, $lng);
}

function fleetopsUtilsAdditionalPolygon(): Polygon
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

test('company transaction currency prefers organization currency and falls back to usd', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-test',
        'currency' => 'mnt',
    ], true);

    expect(Utils::getCompanyTransactionCurrency($company))->toBe('MNT')
        ->and(Utils::getCompanyTransactionCurrency())->toBe('USD');
});

test('mixed point resolver reads location-bearing model instances', function () {
    $place   = new Place();
    $driver  = new Driver();
    $vehicle = new Vehicle();
    $generic = new class extends EloquentModel {
        protected $fillable = ['location'];
    };

    $place->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(1.1, 2.2)], true);
    $driver->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(3.3, 4.4)], true);
    $vehicle->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(5.5, 6.6)], true);
    $generic->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(7.7, 8.8)], true);

    expect(Utils::getPointFromMixed($place)->getLng())->toEqual(2.2)
        ->and(Utils::getPointFromMixed($driver)->getLat())->toEqual(3.3)
        ->and(Utils::getPointFromMixed($vehicle)->getLng())->toEqual(6.6)
        ->and(Utils::getPointFromMixed($generic)->getLat())->toEqual(7.7);
});

test('strict point resolver accepts spatial expressions points geojson and string formats', function () {
    $point             = fleetopsUtilsAdditionalPoint();
    $spatialExpression = new SpatialExpression($point);
    $geoJsonObject     = (object) [
        'type'        => 'Point',
        'coordinates' => [106.9338169, 47.9131423],
    ];

    expect(Utils::getPointFromCoordinatesStrict($spatialExpression)->getLat())->toEqual(47.9131423)
        ->and(Utils::getPointFromCoordinatesStrict($point))->toBe($point)
        ->and(Utils::getPointFromCoordinatesStrict($geoJsonObject)->getLng())->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinatesStrict('POINT(106.9338169 47.9131423)')->getLat())->toEqual(47.9131423)
        ->and(Utils::getPointFromCoordinatesStrict('LatLng(47.9131423, 106.9338169)')->getLng())->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinatesStrict('47.9131423,106.9338169')->getLat())->toEqual(47.9131423)
        ->and(Utils::getPointFromCoordinatesStrict('47.9131423|106.9338169')->getLng())->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinatesStrict('47.9131423 106.9338169')->getLat())->toEqual(47.9131423);
});

test('vendor and country helpers resolve prefix and globe-backed polygons', function () {
    $polygon = Utils::createPolygonFromCountry('mn');

    expect(Utils::isIntegratedVendorId('integrated_vendor_demo'))->toBeTrue()
        ->and($polygon)->toBeInstanceOf(MultiPolygon::class)
        ->and(Utils::createPolygonFromCountry('missing-country-code'))->toBeNull();
});

test('polygon helpers return centroids and coordinate rings from spatial shapes', function () {
    $polygon      = fleetopsUtilsAdditionalPolygon();
    $multiPolygon = new MultiPolygon([$polygon]);
    $coordinates  = Utils::getCoordinatesFromPolygon($polygon);

    expect(Utils::getPolygonCentroid($polygon))->toBe([103.84, 1.34])
        ->and(Utils::getMultiPolygonCentroid($multiPolygon))->toBe([103.84, 1.34])
        ->and($coordinates)->toHaveCount(5)
        ->and($coordinates[0])->toBe([103.8, 1.3]);
});
