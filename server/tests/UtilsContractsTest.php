<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

test('utility coordinate validators accept only finite coordinate ranges', function () {
    expect(Utils::isLatitude('47.9131423'))->toBeTrue()
        ->and(Utils::isLatitude('-90'))->toBeTrue()
        ->and(Utils::isLatitude('90.0001'))->toBeFalse()
        ->and(Utils::isLatitude(null))->toBeFalse()
        ->and(Utils::isLongitude('106.9338169'))->toBeTrue()
        ->and(Utils::isLongitude('-180'))->toBeTrue()
        ->and(Utils::isLongitude('180.1'))->toBeFalse()
        ->and(Utils::isLongitude('east'))->toBeFalse()
        ->and(Utils::cleanCoordinateString(' +47.913, (106.933)!'))->toBe('47.913106.933');
});

test('utility address formatter composes plain text and html addresses without duplicate parts', function () {
    $place = new \Fleetbase\FleetOps\Models\Place([
        'name'         => 'Depot',
        'street1'      => '123 Main Street',
        'street2'      => 'Main Street',
        'city'         => 'Ulaanbaatar',
        'province'     => 'Ulaanbaatar',
        'postal_code'  => '14200',
        'country_name' => 'Mongolia',
    ]);

    expect(Utils::getAddressStringForPlace($place))->toBe('DEPOT - 123 MAIN STREET - ULAANBAATAR - 14200 - MONGOLIA')
        ->and(Utils::getAddressStringForPlace($place, true, ['postal_code']))
        ->toBe('<address>DEPOT<br>123 MAIN STREET<br>ULAANBAATAR, MONGOLIA</address>');
});

test('utility point resolvers parse common coordinate payloads', function () {
    $geoJson = [
        'type'        => 'Point',
        'coordinates' => [106.9338169, 47.9131423],
    ];

    $feature = [
        'type'     => 'Feature',
        'geometry' => $geoJson,
    ];

    expect(Utils::isGeoJson($geoJson))->toBeTrue()
        ->and(Utils::isGeoJson(['type' => 'GeometryCollection', 'geometries' => []]))->toBeTrue()
        ->and(Utils::isGeoJson(['type' => 'Point']))->toBeFalse()
        ->and(Utils::isCoordinates($geoJson))->toBeTrue()
        ->and(Utils::isCoordinatesStrict('47.9131423,106.9338169'))->toBeTrue()
        ->and(Utils::isCoordinatesStrict('place_123'))->toBeFalse();

    $pointFromGeoJson = Utils::getPointFromMixed($geoJson);
    $pointFromFeature = Utils::getPointFromCoordinatesStrict($feature);
    $pointFromWkt     = Utils::getPointFromMixed('POINT(106.9338169 47.9131423)');
    $pointFromLatLng  = Utils::getPointFromMixed('LatLng(47.9131423, 106.9338169)');

    expect($pointFromGeoJson)->toBeInstanceOf(Point::class)
        ->and($pointFromGeoJson->getLat())->toEqual(47.9131423)
        ->and($pointFromGeoJson->getLng())->toEqual(106.9338169)
        ->and($pointFromFeature)->toBeInstanceOf(Point::class)
        ->and($pointFromFeature->getLat())->toEqual(47.9131423)
        ->and($pointFromWkt->getLat())->toEqual(47.9131423)
        ->and($pointFromWkt->getLng())->toEqual(106.9338169)
        ->and($pointFromLatLng->getLat())->toEqual(47.9131423)
        ->and($pointFromLatLng->getLng())->toEqual(106.9338169);
});

test('utility coordinate helpers extract coordinates and fall back to origin safely', function () {
    $point = new Point(47.9131423, 106.9338169);

    expect(Utils::getLatitudeFromCoordinates($point))->toEqual(47.9131423)
        ->and(Utils::getLongitudeFromCoordinates(['lat' => 47.9131423, 'lng' => 106.9338169]))->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinates(null)->getLat())->toEqual(0.0)
        ->and(Utils::getPointFromCoordinates(null)->getLng())->toEqual(0.0)
        ->and(Utils::castPoint('not coordinates')->getLat())->toEqual(0.0)
        ->and(Utils::castPoint('not coordinates')->getLng())->toEqual(0.0)
        ->and(Utils::createCoordinateStringFromPlacesArray([
            ['lat' => 47.9131423, 'lng' => 106.9338169],
            'invalid',
        ]))->toBe('47.9131423,106.9338169|0,0');
});

test('utility geometry summaries and formatters return stable values', function () {
    expect(Utils::formatMeters(999))->toBe('999 m')
        ->and(Utils::formatMeters(1500, false))->toBe('1.5 kilometers')
        ->and(Utils::getCentroid([]))->toBe([0, 0])
        ->and(Utils::getCentroid([['bad'], [10, 20], [30, 40]]))->toBe([20, 30])
        ->and(Utils::coordsToCircle(47.9131423, 106.9338169, 1))->toHaveCount(122)
        ->and(Utils::getNearestTimezone(new Point(40.7128, -74.0060), 'US'))->toBe('America/New_York')
        ->and(round(Utils::calculateHeading(new Point(0, 0), new Point(0, 1)), 1))->toEqual(90.0)
        ->and(Utils::fixPhone('97612345678'))->toBe('+97612345678')
        ->and(Utils::fixPhone('+97612345678'))->toBe('+97612345678');
});

test('utility distance helpers calculate deterministic preliminary distance matrices', function () {
    $origin      = new Point(47.9131423, 106.9338169);
    $destination = new Point(47.9141423, 106.9348169);

    $distance = Utils::vincentyGreatCircleDistance($origin, $destination);
    $matrix   = Utils::getPreliminaryDistanceMatrix($origin, $destination);

    expect($distance)->toBeGreaterThan(0)
        ->and($matrix->distance)->toBe($distance)
        ->and($matrix->time)->toBe((float) round($distance / 100) * Utils::DRIVING_TIME_MULTIPLIER);
});

test('utility type predicates recognize points and completed activities', function () {
    $activity       = new Activity(['code' => 'completed']);
    $emptyActivity  = new Activity(['code' => '']);

    expect(Utils::isPoint(new Point(0, 0)))->toBeTrue()
        ->and(Utils::isPoint(['lat' => 0, 'lng' => 0]))->toBeFalse()
        ->and(Utils::isActivity($activity))->toBeTrue()
        ->and(Utils::isActivity($emptyActivity))->toBeFalse()
        ->and(Utils::isActivity(null))->toBeFalse();
});
