<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPathServiceType;
use Fleetbase\FleetOps\Support\IntegratedVendors;

function ppEntry(): array
{
    foreach (IntegratedVendors::$supported as $entry) {
        if (($entry['code'] ?? null) === 'parcelpath') {
            return $entry;
        }
    }
    test()->fail('parcelpath entry not registered in IntegratedVendors::$supported');
}

test('parcelpath entry is registered with the expected core fields', function () {
    $entry = ppEntry();

    expect($entry['name'])->toBe('ParcelPath');
    expect($entry['host'])->toBe('https://api.parcelpath.com/');
    expect($entry['sandbox'])->toBe('https://api-sandbox.parcelpath.com/');
    expect($entry['namespace'])->toBe('v1');
    expect($entry['bridge'])->toBe(ParcelPath::class);
    expect($entry['svc_bridge'])->toBe(ParcelPathServiceType::class);
    expect($entry['iso2cc_bridge'])->toBeNull();
});

test('parcelpath entry declares api_key as the only credential param', function () {
    $entry = ppEntry();
    $keys = array_column($entry['credentialParams'], 'key');
    expect($keys)->toBe(['api_key']);
});

test('parcelpath entry declares all required option params', function () {
    $entry = ppEntry();
    $keys = array_column($entry['optionParams'], 'key');

    expect($keys)->toContain('carrier_filter');
    expect($keys)->toContain('label_format');
    expect($keys)->toContain('insurance_default');
    expect($keys)->toContain('markup_type');
    expect($keys)->toContain('markup_amount');
    expect($keys)->toContain('client_label');
});

test('parcelpath bridgeParams map credentials.api_key to apiKey', function () {
    $entry = ppEntry();
    expect($entry['bridgeParams']['apiKey'])->toBe('credentials.api_key');
    expect($entry['bridgeParams']['sandbox'])->toBe('sandbox');
});

test('carrier_filter option exposes ups, usps, and all', function () {
    $entry = ppEntry();
    $carrierFilter = collect($entry['optionParams'])->firstWhere('key', 'carrier_filter');
    $values = array_column($carrierFilter['options'], 'value');
    expect($values)->toEqualCanonicalizing(['all', 'ups', 'usps']);
});

test('label_format option exposes PDF and ZPL', function () {
    $entry = ppEntry();
    $fmt = collect($entry['optionParams'])->firstWhere('key', 'label_format');
    $values = array_column($fmt['options'], 'value');
    expect($values)->toEqualCanonicalizing(['PDF', 'ZPL']);
});

test('insurance_default option exposes none, auto, and prompt', function () {
    $entry = ppEntry();
    $ins = collect($entry['optionParams'])->firstWhere('key', 'insurance_default');
    $values = array_column($ins['options'], 'value');
    expect($values)->toEqualCanonicalizing(['none', 'auto', 'prompt']);
});

test('parcelpath has no callbacks (no webhook registration)', function () {
    $entry = ppEntry();
    expect($entry['callbacks'])->toBe([]);
});
