<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;

// ── normalizeTrackingResponse ────────────────────────────────────────────

test('normalizeTrackingResponse returns mapped status, carrier and events array', function () {
    $row = ParcelPath::normalizeTrackingResponse([
        'status'  => 'in_transit',
        'carrier' => 'ups',
        'events'  => [
            ['code' => 'pickup', 'status' => 'Picked up', 'timestamp' => '2026-04-07T10:00:00Z', 'location' => 'SFO', 'details' => 'Origin scan'],
        ],
    ]);
    expect($row['status'])->toBe('IN_TRANSIT');
    expect($row['carrier'])->toBe('UPS');
    expect($row['events'])->toHaveCount(1);
    expect($row['events'][0]['code'])->toBe('PICKUP');
    expect($row['events'][0]['status'])->toBe('Picked up');
    expect($row['events'][0]['timestamp'])->toBe('2026-04-07T10:00:00Z');
    expect($row['events'][0]['location'])->toBe('SFO');
    expect($row['events'][0]['details'])->toBe('Origin scan');
});

test('normalizeTrackingResponse uppercases status and carrier', function () {
    $row = ParcelPath::normalizeTrackingResponse(['status' => 'delivered', 'carrier' => 'usps']);
    expect($row['status'])->toBe('DELIVERED');
    expect($row['carrier'])->toBe('USPS');
});

test('normalizeTrackingResponse uppercases event codes', function () {
    $row = ParcelPath::normalizeTrackingResponse([
        'events' => [['code' => 'delivered'], ['code' => 'out_for_delivery']],
    ]);
    expect($row['events'][0]['code'])->toBe('DELIVERED');
    expect($row['events'][1]['code'])->toBe('OUT_FOR_DELIVERY');
});

test('normalizeTrackingResponse preserves event order', function () {
    $row = ParcelPath::normalizeTrackingResponse([
        'events' => [
            ['code' => 'a'],
            ['code' => 'b'],
            ['code' => 'c'],
        ],
    ]);
    expect(array_column($row['events'], 'code'))->toBe(['A', 'B', 'C']);
});

test('normalizeTrackingResponse defaults status to UNKNOWN when missing', function () {
    $row = ParcelPath::normalizeTrackingResponse(['carrier' => 'ups']);
    expect($row['status'])->toBe('UNKNOWN');
});

test('normalizeTrackingResponse defaults carrier to empty string when missing', function () {
    $row = ParcelPath::normalizeTrackingResponse(['status' => 'delivered']);
    expect($row['carrier'])->toBe('');
});

test('normalizeTrackingResponse returns empty events array when key is missing', function () {
    $row = ParcelPath::normalizeTrackingResponse([]);
    expect($row['events'])->toBe([]);
    expect($row['status'])->toBe('UNKNOWN');
    expect($row['carrier'])->toBe('');
});

test('normalizeTrackingResponse handles events with null location and details', function () {
    $row = ParcelPath::normalizeTrackingResponse([
        'events' => [['code' => 'x', 'status' => 'ok']],
    ]);
    expect($row['events'][0]['location'])->toBeNull();
    expect($row['events'][0]['details'])->toBeNull();
    expect($row['events'][0]['timestamp'])->toBeNull();
});

// ── normalizeVoidResponse ────────────────────────────────────────────────

test('normalizeVoidResponse returns true on {voided: true}', function () {
    expect(ParcelPath::normalizeVoidResponse(['voided' => true]))->toBeTrue();
});

test('normalizeVoidResponse returns true on {status: voided} case-insensitive', function () {
    expect(ParcelPath::normalizeVoidResponse(['status' => 'voided']))->toBeTrue();
    expect(ParcelPath::normalizeVoidResponse(['status' => 'VOIDED']))->toBeTrue();
    expect(ParcelPath::normalizeVoidResponse(['status' => 'Voided']))->toBeTrue();
});

test('normalizeVoidResponse returns true on {status: cancelled}', function () {
    expect(ParcelPath::normalizeVoidResponse(['status' => 'cancelled']))->toBeTrue();
    expect(ParcelPath::normalizeVoidResponse(['status' => 'CANCELLED']))->toBeTrue();
});

test('normalizeVoidResponse returns false on {voided: false}', function () {
    expect(ParcelPath::normalizeVoidResponse(['voided' => false]))->toBeFalse();
});

test('normalizeVoidResponse returns false on empty array', function () {
    expect(ParcelPath::normalizeVoidResponse([]))->toBeFalse();
});

test('normalizeVoidResponse returns false on {status: pending}', function () {
    expect(ParcelPath::normalizeVoidResponse(['status' => 'pending']))->toBeFalse();
});
