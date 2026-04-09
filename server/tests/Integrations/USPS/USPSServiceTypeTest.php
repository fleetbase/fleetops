<?php

use Fleetbase\FleetOps\Integrations\USPS\USPSServiceType;

test('all() returns 5 USPS service types', function () {
    expect(USPSServiceType::all())->toHaveCount(5);
});

test('every USPS service is discoverable by key', function () {
    $expected = ['PRIORITY', 'PRIORITY_EXPRESS', 'GROUND_ADVANTAGE', 'FIRST_CLASS', 'MEDIA_MAIL'];
    foreach ($expected as $key) {
        expect(USPSServiceType::find($key))->not->toBeNull("service $key should exist");
    }
});

test('PRIORITY maps to USPS mail class PRIORITY_MAIL', function () {
    $type = USPSServiceType::find('PRIORITY');
    expect($type->description)->toBe('USPS Priority Mail');
    expect($type->mail_class)->toBe('PRIORITY_MAIL');
});

test('PRIORITY_EXPRESS maps to USPS mail class PRIORITY_MAIL_EXPRESS', function () {
    expect(USPSServiceType::find('PRIORITY_EXPRESS')->mail_class)->toBe('PRIORITY_MAIL_EXPRESS');
});

test('GROUND_ADVANTAGE maps to USPS mail class USPS_GROUND_ADVANTAGE', function () {
    expect(USPSServiceType::find('GROUND_ADVANTAGE')->mail_class)->toBe('USPS_GROUND_ADVANTAGE');
});

test('FIRST_CLASS maps to USPS mail class FIRST-CLASS_PACKAGE_SERVICE', function () {
    expect(USPSServiceType::find('FIRST_CLASS')->mail_class)->toBe('FIRST-CLASS_PACKAGE_SERVICE');
});

test('MEDIA_MAIL maps to USPS mail class MEDIA_MAIL', function () {
    expect(USPSServiceType::find('MEDIA_MAIL')->mail_class)->toBe('MEDIA_MAIL');
});

test('find() is case-insensitive', function () {
    expect(USPSServiceType::find('priority'))->not->toBeNull();
    expect(USPSServiceType::find('Priority')->description)->toBe('USPS Priority Mail');
});

test('find() returns null for unknown key', function () {
    expect(USPSServiceType::find('FOREVER_STAMPS'))->toBeNull();
});

test('find() accepts a callable', function () {
    $type = USPSServiceType::find(fn ($t) => $t->mail_class === 'MEDIA_MAIL');
    expect($type)->not->toBeNull();
    expect($type->key)->toBe('MEDIA_MAIL');
});

test('every USPS service has carrier USPS', function () {
    foreach (USPSServiceType::all() as $type) {
        expect($type->carrier)->toBe('USPS');
    }
});
