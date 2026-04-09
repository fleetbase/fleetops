<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;

// ── terminalOrderStatus ──────────────────────────────────────────────────

test('terminalOrderStatus DELIVERED returns completed', function () {
    expect(ParcelPath::terminalOrderStatus('DELIVERED'))->toBe('completed');
});

test('terminalOrderStatus is case-insensitive for delivered', function () {
    expect(ParcelPath::terminalOrderStatus('delivered'))->toBe('completed');
});

test('terminalOrderStatus RETURN_TO_SENDER returns returned', function () {
    expect(ParcelPath::terminalOrderStatus('RETURN_TO_SENDER'))->toBe('returned');
});

test('terminalOrderStatus RETURNED returns returned', function () {
    expect(ParcelPath::terminalOrderStatus('RETURNED'))->toBe('returned');
});

test('terminalOrderStatus IN_TRANSIT returns null', function () {
    expect(ParcelPath::terminalOrderStatus('IN_TRANSIT'))->toBeNull();
});

test('terminalOrderStatus OUT_FOR_DELIVERY returns null', function () {
    expect(ParcelPath::terminalOrderStatus('OUT_FOR_DELIVERY'))->toBeNull();
});

test('terminalOrderStatus EXCEPTION returns null', function () {
    expect(ParcelPath::terminalOrderStatus('EXCEPTION'))->toBeNull();
});

test('terminalOrderStatus empty string returns null', function () {
    expect(ParcelPath::terminalOrderStatus(''))->toBeNull();
});

test('terminalOrderStatus arbitrary string returns null', function () {
    expect(ParcelPath::terminalOrderStatus('WAT_IS_THIS'))->toBeNull();
});
