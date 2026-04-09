<?php

use Fleetbase\FleetOps\Support\IntegratedVendorResolver;

/**
 * Helper: build a candidate row the way the impure wrapper would when
 * it queries IntegratedVendor via Eloquent. Only the three fields the
 * pure chooser looks at are populated (uuid, provider,
 * shipper_client_uuid).
 */
function cand(string $uuid, string $provider, ?string $shipperClientUuid): array
{
    return [
        'uuid'                => $uuid,
        'provider'            => $provider,
        'shipper_client_uuid' => $shipperClientUuid,
    ];
}

// ── Exact match vs fallback ──────────────────────────────────────────────

test('prefers client-specific match when it exists for the shipper client', function () {
    $candidates = [
        cand('uuid-default-ups', 'ups', null),
        cand('uuid-acme-ups',    'ups', 'acme-vendor-uuid'),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: 'acme-vendor-uuid'
    );

    expect($chosen)->toBe(['uuid-acme-ups']);
});

test('falls back to the null shipper_client_uuid catch-all when no client-specific match exists', function () {
    $candidates = [
        cand('uuid-default-ups', 'ups', null),
        cand('uuid-other-ups',   'ups', 'other-vendor-uuid'),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: 'acme-vendor-uuid'
    );

    expect($chosen)->toBe(['uuid-default-ups']);
});

test('chooses client-specific match even when catch-all comes first in candidate list', function () {
    // Ensures ordering is not load-bearing on the resolution rule.
    $candidates = [
        cand('uuid-default-ups', 'ups', null),
        cand('uuid-other-ups',   'ups', 'other-vendor-uuid'),
        cand('uuid-acme-ups',    'ups', 'acme-vendor-uuid'),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: 'acme-vendor-uuid'
    );

    expect($chosen)->toBe(['uuid-acme-ups']);
});

// ── Multiple providers ───────────────────────────────────────────────────

test('resolves across multiple providers — one row per provider', function () {
    $candidates = [
        cand('uuid-default-parcelpath', 'parcelpath', null),
        cand('uuid-default-ups',        'ups',        null),
        cand('uuid-default-usps',       'usps',       null),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: null
    );

    expect($chosen)->toHaveCount(3);
    expect($chosen)->toContain('uuid-default-parcelpath');
    expect($chosen)->toContain('uuid-default-ups');
    expect($chosen)->toContain('uuid-default-usps');
});

test('resolves multiple providers with mixed client-specific and fallback matches', function () {
    $candidates = [
        // ParcelPath only has the catch-all
        cand('uuid-default-parcelpath', 'parcelpath', null),
        // UPS has both a catch-all and a client-specific
        cand('uuid-default-ups', 'ups', null),
        cand('uuid-acme-ups',    'ups', 'acme-vendor-uuid'),
        // USPS only has a client-specific (no catch-all)
        cand('uuid-acme-usps', 'usps', 'acme-vendor-uuid'),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: 'acme-vendor-uuid'
    );

    expect($chosen)->toHaveCount(3);
    expect($chosen)->toContain('uuid-default-parcelpath');
    expect($chosen)->toContain('uuid-acme-ups');
    expect($chosen)->toContain('uuid-acme-usps');
    expect($chosen)->not->toContain('uuid-default-ups');
});

// ── Missing provider handling ────────────────────────────────────────────

test('silently skips a provider when it has no candidates at all', function () {
    // UPS exists but USPS has no rows; USPS is simply absent from the result.
    $candidates = [
        cand('uuid-default-ups', 'ups', null),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: null
    );

    expect($chosen)->toBe(['uuid-default-ups']);
});

test('silently skips a provider when no candidate matches the shipper and no catch-all exists', function () {
    // UPS only has an other-client row; no catch-all. The request comes in
    // for acme — UPS cannot be resolved for this shipper client and must
    // be dropped rather than routing through the wrong account.
    $candidates = [
        cand('uuid-default-parcelpath', 'parcelpath', null),
        cand('uuid-other-ups',          'ups',        'other-vendor-uuid'),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: 'acme-vendor-uuid'
    );

    expect($chosen)->toBe(['uuid-default-parcelpath']);
});

test('returns empty array when candidate list is empty', function () {
    expect(IntegratedVendorResolver::chooseVendorUuids(
        candidates: [],
        shipperClientUuid: 'acme-vendor-uuid'
    ))->toBe([]);
});

// ── Null shipper client — direct-customer / non-broker case ────────────

test('null shipper client uses only whereNull candidates', function () {
    // When the order's customer is not a Vendor (e.g. a Contact), we pass
    // shipperClientUuid=null. The resolver must only consider catch-all
    // rows, ignoring any client-specific credentials on the broker's
    // account that were registered for other clients.
    $candidates = [
        cand('uuid-default-ups', 'ups', null),
        cand('uuid-acme-ups',    'ups', 'acme-vendor-uuid'),
        cand('uuid-other-ups',   'ups', 'other-vendor-uuid'),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: null
    );

    expect($chosen)->toBe(['uuid-default-ups']);
});

// ── Provider filter ──────────────────────────────────────────────────────

test('provider filter restricts the resolver to the requested carriers only', function () {
    $candidates = [
        cand('uuid-default-parcelpath', 'parcelpath', null),
        cand('uuid-default-ups',        'ups',        null),
        cand('uuid-default-usps',       'usps',       null),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: null,
        providerFilter: ['ups', 'usps']
    );

    expect($chosen)->toHaveCount(2);
    expect($chosen)->toContain('uuid-default-ups');
    expect($chosen)->toContain('uuid-default-usps');
    expect($chosen)->not->toContain('uuid-default-parcelpath');
});

test('empty provider filter is treated as no filter (all providers allowed)', function () {
    $candidates = [
        cand('uuid-default-parcelpath', 'parcelpath', null),
        cand('uuid-default-ups',        'ups',        null),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: null,
        providerFilter: []
    );

    expect($chosen)->toHaveCount(2);
});

test('null provider filter is treated as no filter', function () {
    $candidates = [
        cand('uuid-default-parcelpath', 'parcelpath', null),
        cand('uuid-default-ups',        'ups',        null),
    ];

    $chosen = IntegratedVendorResolver::chooseVendorUuids(
        $candidates,
        shipperClientUuid: null,
        providerFilter: null
    );

    expect($chosen)->toHaveCount(2);
});

// ── Determinism guard ───────────────────────────────────────────────────

test('result is deterministic regardless of candidate array order', function () {
    $a = [
        cand('uuid-default-parcelpath', 'parcelpath', null),
        cand('uuid-default-ups',        'ups',        null),
        cand('uuid-default-usps',       'usps',       null),
    ];
    $b = array_reverse($a);

    $resultA = IntegratedVendorResolver::chooseVendorUuids($a, shipperClientUuid: null);
    $resultB = IntegratedVendorResolver::chooseVendorUuids($b, shipperClientUuid: null);

    sort($resultA);
    sort($resultB);
    expect($resultA)->toBe($resultB);
});
