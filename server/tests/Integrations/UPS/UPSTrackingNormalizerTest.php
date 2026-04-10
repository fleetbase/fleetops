<?php

use Fleetbase\FleetOps\Integrations\UPS\UPS;

// ── upsActivityCodeToFleetbaseCode ───────────────────────────────────────

test('I maps to IN_TRANSIT', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('I'))->toBe('IN_TRANSIT');
});

test('D maps to DELIVERED', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('D'))->toBe('DELIVERED');
});

test('X maps to EXCEPTION', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('X'))->toBe('EXCEPTION');
});

test('P maps to PICKED_UP', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('P'))->toBe('PICKED_UP');
});

test('M maps to MANIFESTED', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('M'))->toBe('MANIFESTED');
});

test('O maps to OUT_FOR_DELIVERY', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('O'))->toBe('OUT_FOR_DELIVERY');
});

test('RS maps to RETURN_TO_SENDER', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('RS'))->toBe('RETURN_TO_SENDER');
});

test('code mapping is case-insensitive', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('d'))->toBe('DELIVERED');
    expect(UPS::upsActivityCodeToFleetbaseCode('rs'))->toBe('RETURN_TO_SENDER');
    expect(UPS::upsActivityCodeToFleetbaseCode('i'))->toBe('IN_TRANSIT');
});

test('unknown codes pass through uppercased', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode('Z'))->toBe('Z');
    expect(UPS::upsActivityCodeToFleetbaseCode('foo'))->toBe('FOO');
});

test('empty string returns empty string', function () {
    expect(UPS::upsActivityCodeToFleetbaseCode(''))->toBe('');
});

// ── normalizeTrackingResponse ────────────────────────────────────────────

test('normalizeTrackingResponse maps UPS activity codes and extracts location', function () {
    $resp = [
        'trackResponse' => [
            'shipment' => [[
                'package' => [[
                    'activity' => [
                        [
                            'status' => ['type' => 'I', 'description' => 'In Transit'],
                            'location' => ['address' => ['city' => 'Louisville', 'stateProvince' => 'KY']],
                            'date' => '20260407',
                            'time' => '103000',
                        ],
                        [
                            'status' => ['type' => 'D', 'description' => 'Delivered'],
                            'location' => ['address' => ['city' => 'New York', 'stateProvince' => 'NY']],
                            'date' => '20260409',
                            'time' => '142200',
                        ],
                    ],
                ]],
            ]],
        ],
    ];

    $result = UPS::normalizeTrackingResponse($resp);

    expect($result['carrier'])->toBe('UPS');
    expect($result['events'])->toHaveCount(2);

    expect($result['events'][0]['code'])->toBe('IN_TRANSIT');
    expect($result['events'][0]['location'])->toBe('Louisville, KY');
    expect($result['events'][0]['timestamp'])->toBe('2026-04-07T10:30:00');

    expect($result['events'][1]['code'])->toBe('DELIVERED');
    expect($result['events'][1]['location'])->toBe('New York, NY');

    // Status is derived from the last event
    expect($result['status'])->toBe('DELIVERED');
});

test('normalizeTrackingResponse handles RS (return to sender)', function () {
    $resp = [
        'trackResponse' => [
            'shipment' => [[
                'package' => [[
                    'activity' => [[
                        'status' => ['type' => 'RS', 'description' => 'Returned'],
                        'date' => '20260410',
                        'time' => '080000',
                    ]],
                ]],
            ]],
        ],
    ];

    $result = UPS::normalizeTrackingResponse($resp);
    expect($result['events'][0]['code'])->toBe('RETURN_TO_SENDER');
    expect($result['status'])->toBe('RETURN_TO_SENDER');
});

test('normalizeTrackingResponse returns UNKNOWN status when activity is missing', function () {
    expect(UPS::normalizeTrackingResponse([]))->toBe([
        'status'  => 'UNKNOWN',
        'carrier' => 'UPS',
        'events'  => [],
    ]);
});

test('normalizeTrackingResponse handles single activity as object (not array)', function () {
    $resp = [
        'trackResponse' => [
            'shipment' => [[
                'package' => [[
                    'activity' => [
                        'status' => ['type' => 'D', 'description' => 'Delivered'],
                        'date' => '20260409',
                        'time' => '120000',
                    ],
                ]],
            ]],
        ],
    ];

    $result = UPS::normalizeTrackingResponse($resp);
    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0]['code'])->toBe('DELIVERED');
});

test('normalizeTrackingResponse handles missing location gracefully', function () {
    $resp = [
        'trackResponse' => [
            'shipment' => [[
                'package' => [[
                    'activity' => [[
                        'status' => ['type' => 'I'],
                        'date' => '20260407',
                        'time' => '100000',
                    ]],
                ]],
            ]],
        ],
    ];

    $result = UPS::normalizeTrackingResponse($resp);
    expect($result['events'][0]['location'])->toBeNull();
});
