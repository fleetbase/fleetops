<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;

function fleetopsBboxPoint(): array
{
    return [
        'bbox'        => [103.851, 1.2816, 103.851, 1.2816],
        'type'        => 'Point',
        'coordinates' => [103.851, 1.2816],
    ];
}

test('point resolution accepts GeoJSON point with bbox', function () {
    $point = Utils::getPointFromMixed(fleetopsBboxPoint());

    expect($point->getLat())->toBe(1.2816)
        ->and($point->getLng())->toBe(103.851);
});

test('point resolution accepts feature wrapped GeoJSON point with bbox', function () {
    $point = Utils::getPointFromMixed([
        'type'     => 'Feature',
        'geometry' => fleetopsBboxPoint(),
    ]);

    expect($point->getLat())->toBe(1.2816)
        ->and($point->getLng())->toBe(103.851);
});

test('point resolution rejects malformed feature points cleanly', function () {
    expect(fn () => Utils::getPointFromMixed([
        'type'     => 'Feature',
        'geometry' => [
            'type'        => 'Point',
            'coordinates' => [],
        ],
    ]))->toThrow(Exception::class, 'Attempted to resolve Point from invalid location.');
});

test('point resolution falls back to nested geometry coordinates', function () {
    // A geometry-typed envelope that also carries a nested `geometry` — the
    // shape some upstream payloads produce when a Feature is flattened badly.
    // The top-level coordinates are empty, so Point::fromJson fails and the
    // resolution falls through to the nested pair rather than giving up.
    $point = Utils::getPointFromMixed([
        'type'        => 'Point',
        'coordinates' => [],
        'geometry'    => [
            'type'        => 'Point',
            'coordinates' => [103.851, 1.2816],
        ],
    ]);

    // The nested pair is GeoJSON, so it is read as [lng, lat]
    expect($point->getLat())->toBe(1.2816)
        ->and($point->getLng())->toBe(103.851);
});

test('point resolution falls back to top level coordinates before nested geometry', function () {
    // The top-level `coordinates` arm is preferred, and is read in the same
    // GeoJSON order. Point::fromJson rejects the envelope because of the extra
    // member, which is what pushes resolution into the fallback at all.
    $point = Utils::getPointFromMixed([
        'type'        => 'Point',
        'coordinates' => [103.851, 1.2816],
        'geometry'    => [
            'type'        => 'Point',
            'coordinates' => [1.0, 2.0],
        ],
    ]);

    expect($point->getLat())->toBe(1.2816)
        ->and($point->getLng())->toBe(103.851);
});

test('point resolution still hands nested rings to the positional reader', function () {
    // A Polygon ring is not a coordinate pair, so the GeoJSON read declines and
    // resolution falls through to the existing recursion unchanged.
    $point = Utils::getPointFromMixed([
        'type'        => 'Polygon',
        'coordinates' => [
            [
                [103.0, 1.0],
                [104.0, 1.0],
                [104.0, 2.0],
                [103.0, 1.0],
            ],
        ],
    ]);

    expect($point)->toBeInstanceOf(Fleetbase\LaravelMysqlSpatial\Types\Point::class);
});

test('coordinate helper does not collapse bbox GeoJSON point to zero', function () {
    $point = Utils::getPointFromCoordinates([
        'type'     => 'Feature',
        'geometry' => fleetopsBboxPoint(),
    ]);

    expect($point->getLat())->toBe(1.2816)
        ->and($point->getLng())->toBe(103.851);
});

test('structured place normalization accepts bbox GeoJSON point locations', function () {
    $place = Place::mergeStructuredPlaceAttributes([
        'name'     => 'AI Pickup',
        'street1'  => '16 Simon Walk',
        'city'     => 'Singapore',
        'country'  => 'SG',
        'location' => fleetopsBboxPoint(),
    ]);

    expect($place['location']->getLat())->toBe(1.2816)
        ->and($place['location']->getLng())->toBe(103.851);
});
