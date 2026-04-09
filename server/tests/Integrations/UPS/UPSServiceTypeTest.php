<?php

use Fleetbase\FleetOps\Integrations\UPS\UPSServiceType;

test('all() returns 8 UPS service types', function () {
    expect(UPSServiceType::all())->toHaveCount(8);
});

test('every UPS service has the correct carrier code', function () {
    $expected = [
        'GROUND'       => '03',
        'GROUND_SAVER' => '93',
        '2DA'          => '02',
        '2DAM'         => '59',
        '1DA'          => '01',
        '1DAM'         => '14',
        '1DASAVER'     => '13',
        '3DS'          => '12',
    ];

    foreach ($expected as $key => $code) {
        $type = UPSServiceType::find($key);
        expect($type)->not->toBeNull("service $key should exist");
        expect($type->service_code)->toBe($code, "service $key should map to code $code");
    }
});

test('find() returns Ground with full metadata', function () {
    $type = UPSServiceType::find('GROUND');
    expect($type)->not->toBeNull();
    expect($type->service_code)->toBe('03');
    expect($type->description)->toBe('UPS Ground');
});

test('find() returns Ground Saver with code 93', function () {
    $type = UPSServiceType::find('GROUND_SAVER');
    expect($type->service_code)->toBe('93');
    expect($type->description)->toBe('UPS Ground Saver');
});

test('find() returns Next Day Air Early with code 14', function () {
    expect(UPSServiceType::find('1DAM')->service_code)->toBe('14');
});

test('find() is case-insensitive', function () {
    expect(UPSServiceType::find('ground'))->not->toBeNull();
    expect(UPSServiceType::find('Ground')->service_code)->toBe('03');
});

test('find() returns null for unknown key', function () {
    expect(UPSServiceType::find('EXPRESS'))->toBeNull();
});

test('find() accepts a callable', function () {
    $type = UPSServiceType::find(fn ($t) => $t->service_code === '01');
    expect($type)->not->toBeNull();
    expect($type->key)->toBe('1DA');
});

test('all() entries have key, description, service_code, carrier keys', function () {
    foreach (UPSServiceType::all() as $type) {
        expect($type->key)->not->toBeNull();
        expect($type->description)->not->toBeNull();
        expect($type->service_code)->not->toBeNull();
        expect($type->carrier)->toBe('UPS');
    }
});
