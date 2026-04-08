<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPathServiceType;

test('all() returns 13 service types', function () {
    expect(ParcelPathServiceType::all())->toHaveCount(13);
});

test('find() returns UPS Ground with correct metadata', function () {
    $type = ParcelPathServiceType::find('PP_UPS_GROUND');
    expect($type)->not->toBeNull();
    expect($type->carrier)->toBe('UPS');
    expect($type->pp_v9)->toBe('ups_ground');
    expect($type->description)->toBe('UPS Ground');
});

test('find() is case-insensitive', function () {
    expect(ParcelPathServiceType::find('pp_ups_ground'))->not->toBeNull();
});

test('find() returns null for unknown key', function () {
    expect(ParcelPathServiceType::find('NOPE'))->toBeNull();
});

test('find() accepts a callable', function () {
    $type = ParcelPathServiceType::find(fn ($t) => $t->key === 'PP_USPS_MEDIA');
    expect($type)->not->toBeNull();
    expect($type->pp_v9)->toBe('MediaMail');
});

test('all UPS services are present', function () {
    $upsKeys = ParcelPathServiceType::all()
        ->filter(fn ($t) => $t->carrier === 'UPS')
        ->map(fn ($t) => $t->key)
        ->all();

    expect($upsKeys)->toContain(
        'PP_UPS_GROUND',
        'PP_UPS_GROUND_SAVER',
        'PP_UPS_3DS',
        'PP_UPS_2DA',
        'PP_UPS_2DAM',
        'PP_UPS_1DA',
        'PP_UPS_1DAM',
        'PP_UPS_1DASAVER'
    );
    expect(count($upsKeys))->toBe(8);
});

test('all USPS services are present', function () {
    $uspsKeys = ParcelPathServiceType::all()
        ->filter(fn ($t) => $t->carrier === 'USPS')
        ->map(fn ($t) => $t->key)
        ->all();

    expect($uspsKeys)->toContain(
        'PP_USPS_PRIORITY',
        'PP_USPS_EXPRESS',
        'PP_USPS_GROUND_ADV',
        'PP_USPS_FIRST',
        'PP_USPS_MEDIA'
    );
    expect(count($uspsKeys))->toBe(5);
});

test('USPS Priority maps to pp_v9 token Priority', function () {
    expect(ParcelPathServiceType::find('PP_USPS_PRIORITY')->pp_v9)->toBe('Priority');
});
