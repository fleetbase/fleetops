<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;

// ── placeToAddress ───────────────────────────────────────────────────────

test('placeToAddress extracts expected keys from a Place-like object', function () {
    $place = (object) [
        'street1'     => '1600 Pennsylvania Ave NW',
        'city'        => 'Washington',
        'province'    => 'DC',
        'postal_code' => '20500',
        'country'     => 'US',
    ];

    expect(ParcelPath::placeToAddress($place))->toBe([
        'address' => '1600 Pennsylvania Ave NW',
        'city'    => 'Washington',
        'state'   => 'DC',
        'zip'     => '20500',
        'country' => 'US',
    ]);
});

test('placeToAddress defaults country to US when missing', function () {
    $place = (object) [
        'street1'     => '1 Main',
        'city'        => 'Boise',
        'province'    => 'ID',
        'postal_code' => '83702',
    ];

    expect(ParcelPath::placeToAddress($place)['country'])->toBe('US');
});

test('placeToAddress coerces nulls to empty strings', function () {
    $place = (object) ['street1' => null, 'city' => null, 'province' => null, 'postal_code' => null];
    $result = ParcelPath::placeToAddress($place);
    expect($result['address'])->toBe('');
    expect($result['city'])->toBe('');
    expect($result['zip'])->toBe('');
});

// ── entitiesToParcels ────────────────────────────────────────────────────

test('entitiesToParcels maps parcel entities to dimension + weight payload', function () {
    $entities = [
        (object) ['type' => 'parcel', 'length' => 12.0, 'width' => 8.0, 'height' => 4.0, 'weight' => 2.5],
    ];

    $parcels = ParcelPath::entitiesToParcels($entities);

    expect($parcels)->toHaveCount(1);
    expect($parcels[0]['length'])->toBe(12.0);
    expect($parcels[0]['width'])->toBe(8.0);
    expect($parcels[0]['height'])->toBe(4.0);
    expect($parcels[0]['weight'])->toBe(2.5);
    expect($parcels[0]['template'])->toBeNull();
});

test('entitiesToParcels skips non-parcel entities', function () {
    $entities = [
        (object) ['type' => 'parcel',  'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1],
        (object) ['type' => 'document','length' => 9, 'width' => 9, 'height' => 9, 'weight' => 9],
        (object) ['type' => 'parcel',  'length' => 2, 'width' => 2, 'height' => 2, 'weight' => 2],
    ];

    $parcels = ParcelPath::entitiesToParcels($entities);
    expect($parcels)->toHaveCount(2);
});

test('entitiesToParcels treats entity with no type as parcel', function () {
    $entities = [(object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]];
    expect(ParcelPath::entitiesToParcels($entities))->toHaveCount(1);
});

test('entitiesToParcels propagates package_template from meta', function () {
    $entities = [(object) [
        'type' => 'parcel', 'length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1,
        'meta' => ['package_template' => 'medium_flat_rate_box'],
    ]];
    expect(ParcelPath::entitiesToParcels($entities)[0]['template'])->toBe('medium_flat_rate_box');
});

// ── buildRatesRequest ────────────────────────────────────────────────────

test('buildRatesRequest assembles ship_from / ship_to / parcels / carrier_filter', function () {
    $body = ParcelPath::buildRatesRequest(
        ['zip' => '94110'],
        ['zip' => '10001'],
        [['length' => 10, 'width' => 10, 'height' => 10, 'weight' => 3]],
        'all'
    );

    expect($body['ship_from']['zip'])->toBe('94110');
    expect($body['ship_to']['zip'])->toBe('10001');
    expect($body['parcels'])->toHaveCount(1);
    expect($body['carrier_filter'])->toBe('all');
});

test('buildRatesRequest defaults carrier_filter to all when null', function () {
    $body = ParcelPath::buildRatesRequest(['zip' => '1'], ['zip' => '2'], [], null);
    expect($body['carrier_filter'])->toBe('all');
});

test('buildRatesRequest passes through ups/usps carrier_filter', function () {
    expect(ParcelPath::buildRatesRequest(['zip' => '1'], ['zip' => '2'], [], 'ups')['carrier_filter'])->toBe('ups');
    expect(ParcelPath::buildRatesRequest(['zip' => '1'], ['zip' => '2'], [], 'usps')['carrier_filter'])->toBe('usps');
});

// ── normalizeRatesResponse ───────────────────────────────────────────────

test('normalizeRatesResponse converts dollars to integer cents', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [[
        'carrier' => 'UPS', 'service' => 'Ground', 'service_token' => 'ups_ground',
        'amount' => 8.42, 'currency' => 'USD', 'estimated_days' => 5, 'rate_id' => 'rate_abc',
    ]]]);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['amount'])->toBe(842);
    expect($rows[0]['currency'])->toBe('USD');
    expect($rows[0]['service'])->toBe('Ground');
    expect($rows[0]['meta']['carrier'])->toBe('UPS');
    expect($rows[0]['meta']['service_token'])->toBe('ups_ground');
    expect($rows[0]['meta']['pp_rate_id'])->toBe('rate_abc');
    expect($rows[0]['meta']['estimated_days'])->toBe(5);
    expect($rows[0]['meta']['carrier_amount'])->toBe(842);
});

test('normalizeRatesResponse converts insurance_cost to cents when present', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [[
        'carrier' => 'UPS', 'amount' => 8.42, 'insurance_available' => true, 'insurance_cost' => 1.25,
    ]]]);
    expect($rows[0]['meta']['insurance_available'])->toBeTrue();
    expect($rows[0]['meta']['insurance_cost'])->toBe(125);
});

test('normalizeRatesResponse leaves insurance_cost null when absent', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [['amount' => 5.00]]]);
    expect($rows[0]['meta']['insurance_cost'])->toBeNull();
    expect($rows[0]['meta']['insurance_available'])->toBeFalse();
});

test('normalizeRatesResponse handles sub-cent rounding correctly', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [
        ['amount' => 8.425],    // rounds to 843
        ['amount' => 8.424],    // rounds to 842
    ]]);
    expect($rows[0]['amount'])->toBe(843);
    expect($rows[1]['amount'])->toBe(842);
});

test('normalizeRatesResponse returns multiple UPS and USPS rates', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [
        ['carrier' => 'UPS',  'service' => 'Ground',   'amount' => 8.42],
        ['carrier' => 'USPS', 'service' => 'Priority', 'amount' => 7.15],
    ]]);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['meta']['carrier'])->toBe('UPS');
    expect($rows[1]['meta']['carrier'])->toBe('USPS');
    expect($rows[1]['amount'])->toBe(715);
});

test('normalizeRatesResponse skips rate rows without an amount field', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [
        ['carrier' => 'UPS', 'amount' => 5.00],
        ['carrier' => 'UPS', 'error' => 'out_of_service_area'],
        ['carrier' => 'USPS', 'amount' => 6.00],
    ]]);
    expect($rows)->toHaveCount(2);
});

test('normalizeRatesResponse returns empty array when rates key is missing', function () {
    expect(ParcelPath::normalizeRatesResponse([]))->toBe([]);
});

test('normalizeRatesResponse defaults currency to USD when absent', function () {
    $rows = ParcelPath::normalizeRatesResponse(['rates' => [['amount' => 5.00]]]);
    expect($rows[0]['currency'])->toBe('USD');
});
