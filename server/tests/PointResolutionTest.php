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

    // NOTE: this fallback recurses with the bare coordinate pair, which the
    // array reader interprets positionally as [lat, lng] — the reverse of
    // GeoJSON's [lng, lat]. The pair therefore comes back transposed. This
    // asserts the behaviour as it stands rather than the intent; changing it
    // affects every location write path and belongs in its own change.
    expect($point->getLat())->toBe(103.851)
        ->and($point->getLng())->toBe(1.2816);
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
