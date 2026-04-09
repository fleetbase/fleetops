<?php

use Fleetbase\FleetOps\Integrations\USPS\USPS;
use Fleetbase\FleetOps\Integrations\USPS\USPSServiceType;
use Fleetbase\FleetOps\Support\IntegratedVendors;

function uspsEntry(): array
{
    foreach (IntegratedVendors::$supported as $entry) {
        if (($entry['code'] ?? null) === 'usps') {
            return $entry;
        }
    }
    test()->fail('usps entry not registered in IntegratedVendors::$supported');
}

// ── Core shape ───────────────────────────────────────────────────────────

test('usps entry is registered with the expected core fields', function () {
    $entry = uspsEntry();

    expect($entry['name'])->toBe('USPS');
    expect($entry['host'])->toBe('https://api.usps.com/');
    expect($entry['sandbox'])->toBe('https://apis-tem.usps.com/');
    expect($entry['namespace'])->toBe('v3');
    expect($entry['bridge'])->toBe(USPS::class);
    expect($entry['svc_bridge'])->toBe(USPSServiceType::class);
    expect($entry['iso2cc_bridge'])->toBeNull();
});

// ── Credential params (NO account_number) ───────────────────────────────

test('usps entry declares client_id and client_secret credential params only', function () {
    $entry = uspsEntry();
    $keys = array_column($entry['credentialParams'], 'key');
    expect($keys)->toBe(['client_id', 'client_secret']);
});

test('usps entry does NOT declare account_number (rates are zip-scoped)', function () {
    $entry = uspsEntry();
    $keys = array_column($entry['credentialParams'], 'key');
    expect($keys)->not->toContain('account_number');
});

// ── Option params (NO label_format — USPS is PDF-only) ─────────────────

test('usps entry declares markup_type / markup_amount / client_label option params', function () {
    $entry = uspsEntry();
    $keys = array_column($entry['optionParams'], 'key');

    expect($keys)->toContain('markup_type');
    expect($keys)->toContain('markup_amount');
    expect($keys)->toContain('client_label');
});

test('usps entry does NOT declare a label_format option (PDF-only)', function () {
    $entry = uspsEntry();
    $keys = array_column($entry['optionParams'], 'key');
    expect($keys)->not->toContain('label_format');
});

test('usps markup_type option exposes flat and percent', function () {
    $entry = uspsEntry();
    $markup = collect($entry['optionParams'])->firstWhere('key', 'markup_type');
    $values = array_column($markup['options'], 'value');
    expect($values)->toEqualCanonicalizing(['flat', 'percent']);
});

// ── Bridge params ────────────────────────────────────────────────────────

test('usps bridgeParams map credentials and sandbox to constructor args', function () {
    $entry = uspsEntry();
    expect($entry['bridgeParams']['clientId'])->toBe('credentials.client_id');
    expect($entry['bridgeParams']['clientSecret'])->toBe('credentials.client_secret');
    expect($entry['bridgeParams']['sandbox'])->toBe('sandbox');
});

test('usps bridgeParams do NOT include accountNumber', function () {
    $entry = uspsEntry();
    expect(isset($entry['bridgeParams']['accountNumber']))->toBeFalse();
});

// ── Callbacks ────────────────────────────────────────────────────────────

test('usps has no callbacks (no webhook registration)', function () {
    $entry = uspsEntry();
    expect($entry['callbacks'])->toBe([]);
});

// ── Regression guards ────────────────────────────────────────────────────

test('parcelpath entry is still present after USPS registration (regression guard)', function () {
    $parcelpath = collect(IntegratedVendors::$supported)->firstWhere('code', 'parcelpath');
    expect($parcelpath)->not->toBeNull();
});

test('ups entry is still present after USPS registration (regression guard)', function () {
    $ups = collect(IntegratedVendors::$supported)->firstWhere('code', 'ups');
    expect($ups)->not->toBeNull();
});

test('all four providers (lalamove, parcelpath, ups, usps) are registered', function () {
    $codes = array_column(IntegratedVendors::$supported, 'code');
    expect($codes)->toContain('lalamove');
    expect($codes)->toContain('parcelpath');
    expect($codes)->toContain('ups');
    expect($codes)->toContain('usps');
});
