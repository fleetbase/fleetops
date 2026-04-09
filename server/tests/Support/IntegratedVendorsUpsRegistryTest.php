<?php

use Fleetbase\FleetOps\Integrations\UPS\UPS;
use Fleetbase\FleetOps\Integrations\UPS\UPSServiceType;
use Fleetbase\FleetOps\Support\IntegratedVendors;

function upsEntry(): array
{
    foreach (IntegratedVendors::$supported as $entry) {
        if (($entry['code'] ?? null) === 'ups') {
            return $entry;
        }
    }
    test()->fail('ups entry not registered in IntegratedVendors::$supported');
}

// ── Core shape ───────────────────────────────────────────────────────────

test('ups entry is registered with the expected core fields', function () {
    $entry = upsEntry();

    expect($entry['name'])->toBe('UPS');
    expect($entry['host'])->toBe('https://onlinetools.ups.com/');
    expect($entry['sandbox'])->toBe('https://wwwcie.ups.com/');
    expect($entry['namespace'])->toBe('api');
    expect($entry['bridge'])->toBe(UPS::class);
    expect($entry['svc_bridge'])->toBe(UPSServiceType::class);
    expect($entry['iso2cc_bridge'])->toBeNull();
});

// ── Credential params ────────────────────────────────────────────────────

test('ups entry declares client_id, client_secret, and account_number credential params', function () {
    $entry = upsEntry();
    $keys = array_column($entry['credentialParams'], 'key');
    expect($keys)->toBe(['client_id', 'client_secret', 'account_number']);
});

// ── Option params ────────────────────────────────────────────────────────

test('ups entry declares label_format / markup_type / markup_amount / client_label option params', function () {
    $entry = upsEntry();
    $keys = array_column($entry['optionParams'], 'key');

    expect($keys)->toContain('label_format');
    expect($keys)->toContain('markup_type');
    expect($keys)->toContain('markup_amount');
    expect($keys)->toContain('client_label');
});

test('ups label_format option exposes PDF and ZPL', function () {
    $entry = upsEntry();
    $fmt = collect($entry['optionParams'])->firstWhere('key', 'label_format');
    $values = array_column($fmt['options'], 'value');
    expect($values)->toEqualCanonicalizing(['PDF', 'ZPL']);
});

test('ups markup_type option exposes flat and percent', function () {
    $entry = upsEntry();
    $markup = collect($entry['optionParams'])->firstWhere('key', 'markup_type');
    $values = array_column($markup['options'], 'value');
    expect($values)->toEqualCanonicalizing(['flat', 'percent']);
});

// ── Bridge params ────────────────────────────────────────────────────────

test('ups bridgeParams map credentials and sandbox to constructor args', function () {
    $entry = upsEntry();
    expect($entry['bridgeParams']['clientId'])->toBe('credentials.client_id');
    expect($entry['bridgeParams']['clientSecret'])->toBe('credentials.client_secret');
    expect($entry['bridgeParams']['accountNumber'])->toBe('credentials.account_number');
    expect($entry['bridgeParams']['sandbox'])->toBe('sandbox');
});

// ── Callbacks ────────────────────────────────────────────────────────────

test('ups has no callbacks (no webhook registration)', function () {
    $entry = upsEntry();
    expect($entry['callbacks'])->toBe([]);
});

// ── Phase 1 regression guard ─────────────────────────────────────────────

test('parcelpath entry is still present after UPS registration (regression guard)', function () {
    $parcelpath = collect(IntegratedVendors::$supported)->firstWhere('code', 'parcelpath');
    expect($parcelpath)->not->toBeNull();
});
