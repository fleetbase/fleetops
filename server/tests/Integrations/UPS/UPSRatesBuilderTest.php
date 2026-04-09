<?php

use Fleetbase\FleetOps\Integrations\UPS\UPS;

// ── dimensionalWeight ────────────────────────────────────────────────────

test('dimensionalWeight computes L*W*H / divisor', function () {
    // 12 * 9 * 6 = 648; 648 / 139 ≈ 4.66187
    expect(UPS::dimensionalWeight(12, 9, 6))->toEqualWithDelta(4.6619, 0.001);
});

test('dimensionalWeight defaults to domestic UPS divisor 139', function () {
    expect(UPS::dimensionalWeight(10, 10, 10))->toEqualWithDelta(1000 / 139, 0.001);
});

test('dimensionalWeight accepts custom divisor', function () {
    expect(UPS::dimensionalWeight(10, 10, 10, 166))->toEqualWithDelta(1000 / 166, 0.001);
});

// ── billableWeight ───────────────────────────────────────────────────────

test('billableWeight returns actual when actual exceeds dim', function () {
    expect(UPS::billableWeight(5.0, 3.0))->toBe(5.0);
});

test('billableWeight returns dim when dim exceeds actual', function () {
    expect(UPS::billableWeight(2.0, 4.66))->toBe(4.66);
});

test('billableWeight returns either when equal', function () {
    expect(UPS::billableWeight(3.0, 3.0))->toBe(3.0);
});

// ── placeToUpsAddress ────────────────────────────────────────────────────

test('placeToUpsAddress maps street1/city/province/postal/country', function () {
    $place = (object) [
        'street1'     => '1600 Pennsylvania Ave NW',
        'city'        => 'Washington',
        'province'    => 'DC',
        'postal_code' => '20500',
        'country'     => 'US',
    ];
    $addr = UPS::placeToUpsAddress($place);
    expect($addr['AddressLine'])->toBe(['1600 Pennsylvania Ave NW']);
    expect($addr['City'])->toBe('Washington');
    expect($addr['StateProvinceCode'])->toBe('DC');
    expect($addr['PostalCode'])->toBe('20500');
    expect($addr['CountryCode'])->toBe('US');
});

test('placeToUpsAddress defaults CountryCode to US when absent', function () {
    $place = (object) [
        'street1'     => '1 Main',
        'city'        => 'Boise',
        'province'    => 'ID',
        'postal_code' => '83702',
    ];
    expect(UPS::placeToUpsAddress($place)['CountryCode'])->toBe('US');
});

// ── entityToUpsPackage ───────────────────────────────────────────────────

test('entityToUpsPackage builds the UPS Package shape', function () {
    $entity = (object) [
        'type'   => 'parcel',
        'length' => 12.0,
        'width'  => 9.0,
        'height' => 3.0,
        'weight' => 2.5,
    ];
    $pkg = UPS::entityToUpsPackage($entity);

    expect($pkg['PackagingType']['Code'])->toBe('02');
    expect($pkg['Dimensions']['UnitOfMeasurement']['Code'])->toBe('IN');
    expect($pkg['Dimensions']['Length'])->toBe('12');
    expect($pkg['Dimensions']['Width'])->toBe('9');
    expect($pkg['Dimensions']['Height'])->toBe('3');
    expect($pkg['PackageWeight']['UnitOfMeasurement']['Code'])->toBe('LBS');
    // Billable weight = max(actual=2.5, dim=12*9*3/139=2.33) = 2.5
    expect((float) $pkg['PackageWeight']['Weight'])->toEqualWithDelta(2.5, 0.01);
});

test('entityToUpsPackage uses dim weight when it exceeds actual', function () {
    // 24 * 18 * 18 = 7776; 7776 / 139 ≈ 55.94 lb vs actual 10 lb
    $entity = (object) [
        'type'   => 'parcel',
        'length' => 24.0,
        'width'  => 18.0,
        'height' => 18.0,
        'weight' => 10.0,
    ];
    $pkg = UPS::entityToUpsPackage($entity);
    expect((float) $pkg['PackageWeight']['Weight'])->toEqualWithDelta(55.94, 0.1);
});

// ── buildRateShopRequest ─────────────────────────────────────────────────

test('buildRateShopRequest assembles a Shop request with no service code', function () {
    $shipFrom = UPS::placeToUpsAddress((object) ['street1' => '1 A', 'city' => 'X', 'province' => 'CA', 'postal_code' => '90001', 'country' => 'US']);
    $shipTo   = UPS::placeToUpsAddress((object) ['street1' => '1 B', 'city' => 'Y', 'province' => 'NY', 'postal_code' => '10001', 'country' => 'US']);
    $packages = [UPS::entityToUpsPackage((object) ['type' => 'parcel', 'length' => 10, 'width' => 10, 'height' => 10, 'weight' => 5])];

    $body = UPS::buildRateShopRequest($shipFrom, $shipTo, $packages, 'A1B2C3');

    expect($body['RateRequest']['Request']['RequestOption'])->toBe('Shop');
    expect($body['RateRequest']['Shipment']['Shipper']['ShipperNumber'])->toBe('A1B2C3');
    expect($body['RateRequest']['Shipment']['Shipper']['Address']['PostalCode'])->toBe('90001');
    expect($body['RateRequest']['Shipment']['ShipTo']['Address']['PostalCode'])->toBe('10001');
    expect($body['RateRequest']['Shipment']['ShipFrom']['Address']['PostalCode'])->toBe('90001');
    expect($body['RateRequest']['Shipment']['Package'])->toHaveCount(1);
    expect(isset($body['RateRequest']['Shipment']['Service']))->toBeFalse();
});

test('buildRateShopRequest sets Service.Code when serviceCode is provided', function () {
    $shipFrom = UPS::placeToUpsAddress((object) ['street1' => '1', 'city' => 'X', 'province' => 'CA', 'postal_code' => '90001']);
    $shipTo   = UPS::placeToUpsAddress((object) ['street1' => '2', 'city' => 'Y', 'province' => 'NY', 'postal_code' => '10001']);
    $packages = [UPS::entityToUpsPackage((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1])];

    $body = UPS::buildRateShopRequest($shipFrom, $shipTo, $packages, 'A1B2C3', '03');

    expect($body['RateRequest']['Request']['RequestOption'])->toBe('Rate');
    expect($body['RateRequest']['Shipment']['Service']['Code'])->toBe('03');
});

test('buildRateShopRequest supports multiple packages', function () {
    $shipFrom = UPS::placeToUpsAddress((object) ['street1' => '1', 'city' => 'X', 'province' => 'CA', 'postal_code' => '90001']);
    $shipTo   = UPS::placeToUpsAddress((object) ['street1' => '2', 'city' => 'Y', 'province' => 'NY', 'postal_code' => '10001']);
    $packages = [
        UPS::entityToUpsPackage((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]),
        UPS::entityToUpsPackage((object) ['length' => 2, 'width' => 2, 'height' => 2, 'weight' => 2]),
    ];

    $body = UPS::buildRateShopRequest($shipFrom, $shipTo, $packages, 'A1B2C3');
    expect($body['RateRequest']['Shipment']['Package'])->toHaveCount(2);
});

// ── normalizeRateShopResponse ────────────────────────────────────────────

function upsSingleRateResponse(array $ratedShipment): array
{
    // When UPS returns a single rated shipment it may be an object OR an array
    return ['RateResponse' => ['RatedShipment' => $ratedShipment]];
}

test('normalizeRateShopResponse extracts service code and converts dollars to cents', function () {
    $resp = upsSingleRateResponse([
        [
            'Service' => ['Code' => '03'],
            'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '12.40'],
        ],
    ]);

    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 0);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['amount'])->toBe(1240);
    expect($rows[0]['currency'])->toBe('USD');
    expect($rows[0]['meta']['carrier'])->toBe('UPS');
    expect($rows[0]['meta']['service_code'])->toBe('03');
    expect($rows[0]['meta']['carrier_amount'])->toBe(1240);
    expect($rows[0]['meta']['markup_amount'])->toBe(0);
});

test('normalizeRateShopResponse prefers NegotiatedRateCharges over TotalCharges', function () {
    $resp = upsSingleRateResponse([
        [
            'Service' => ['Code' => '03'],
            'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '12.40'],
            'NegotiatedRateCharges' => [
                'TotalCharge' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '10.00'],
            ],
        ],
    ]);

    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 0);
    expect($rows[0]['amount'])->toBe(1000);
    expect($rows[0]['meta']['carrier_amount'])->toBe(1000);
});

test('normalizeRateShopResponse applies flat markup in cents', function () {
    $resp = upsSingleRateResponse([[
        'Service' => ['Code' => '03'],
        'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '10.00'],
    ]]);

    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 50);

    // Carrier 1000 + flat 50 = 1050 sell
    expect($rows[0]['amount'])->toBe(1050);
    expect($rows[0]['meta']['carrier_amount'])->toBe(1000);
    expect($rows[0]['meta']['markup_amount'])->toBe(50);
    expect($rows[0]['meta']['markup_type'])->toBe('flat');
});

test('normalizeRateShopResponse applies percent markup', function () {
    $resp = upsSingleRateResponse([[
        'Service' => ['Code' => '03'],
        'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '10.00'],
    ]]);

    $rows = UPS::normalizeRateShopResponse($resp, 'percent', 10);

    // Carrier 1000 + 10% = 1100 sell
    expect($rows[0]['amount'])->toBe(1100);
    expect($rows[0]['meta']['markup_amount'])->toBe(100);
    expect($rows[0]['meta']['markup_type'])->toBe('percent');
});

test('normalizeRateShopResponse handles multiple RatedShipment entries', function () {
    $resp = upsSingleRateResponse([
        ['Service' => ['Code' => '03'], 'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '12.40']],
        ['Service' => ['Code' => '02'], 'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '24.80']],
        ['Service' => ['Code' => '01'], 'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '45.00']],
    ]);

    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 0);
    expect($rows)->toHaveCount(3);
    expect($rows[0]['meta']['service_code'])->toBe('03');
    expect($rows[1]['meta']['service_code'])->toBe('02');
    expect($rows[2]['meta']['service_code'])->toBe('01');
});

test('normalizeRateShopResponse accepts single RatedShipment as object (not array)', function () {
    // UPS returns a single rated shipment as a direct object, not wrapped in an array
    $resp = [
        'RateResponse' => [
            'RatedShipment' => [
                'Service' => ['Code' => '03'],
                'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '12.40'],
            ],
        ],
    ];

    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 0);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['meta']['service_code'])->toBe('03');
});

test('normalizeRateShopResponse resolves service description via UPSServiceType', function () {
    $resp = upsSingleRateResponse([[
        'Service' => ['Code' => '03'],
        'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '10.00'],
    ]]);

    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 0);
    expect($rows[0]['service'])->toBe('UPS Ground');
});

test('normalizeRateShopResponse returns empty array when RatedShipment is missing', function () {
    $resp = ['RateResponse' => ['Response' => ['ResponseStatus' => ['Code' => '0']]]];
    expect(UPS::normalizeRateShopResponse($resp, 'flat', 0))->toBe([]);
});

test('normalizeRateShopResponse handles sub-cent rounding', function () {
    $resp = upsSingleRateResponse([
        ['Service' => ['Code' => '03'], 'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '8.425']],
        ['Service' => ['Code' => '02'], 'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '8.424']],
    ]);
    $rows = UPS::normalizeRateShopResponse($resp, 'flat', 0);
    expect($rows[0]['amount'])->toBe(843);
    expect($rows[1]['amount'])->toBe(842);
});
