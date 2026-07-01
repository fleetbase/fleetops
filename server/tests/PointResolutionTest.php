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
