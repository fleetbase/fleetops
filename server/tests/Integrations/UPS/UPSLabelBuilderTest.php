<?php

use Fleetbase\FleetOps\Integrations\UPS\UPS;

// ── signatureConfirmationCode ────────────────────────────────────────────

test('signatureConfirmationCode returns 2 for standard', function () {
    expect(UPS::signatureConfirmationCode('standard'))->toBe(2);
});

test('signatureConfirmationCode returns 3 for adult', function () {
    expect(UPS::signatureConfirmationCode('adult'))->toBe(3);
});

test('signatureConfirmationCode is case-insensitive', function () {
    expect(UPS::signatureConfirmationCode('ADULT'))->toBe(3);
    expect(UPS::signatureConfirmationCode('Standard'))->toBe(2);
});

test('signatureConfirmationCode returns null for none/default/unknown', function () {
    expect(UPS::signatureConfirmationCode('none'))->toBeNull();
    expect(UPS::signatureConfirmationCode('default'))->toBeNull();
    expect(UPS::signatureConfirmationCode(''))->toBeNull();
    expect(UPS::signatureConfirmationCode('whatever'))->toBeNull();
    expect(UPS::signatureConfirmationCode(null))->toBeNull();
});

// ── buildShipRequest ─────────────────────────────────────────────────────

function upsShipTestContext(): array
{
    return [
        'shipperName' => 'ACME Shipping',
        'shipFrom' => UPS::placeToUpsAddress((object) [
            'street1' => '1 Warehouse Way',
            'city' => 'Oakland',
            'province' => 'CA',
            'postal_code' => '94607',
            'country' => 'US',
        ]),
        'shipTo' => UPS::placeToUpsAddress((object) [
            'street1' => '350 5th Ave',
            'city' => 'New York',
            'province' => 'NY',
            'postal_code' => '10118',
            'country' => 'US',
        ]),
        'packages' => [
            UPS::entityToUpsPackage((object) [
                'type' => 'parcel', 'length' => 12, 'width' => 9, 'height' => 3, 'weight' => 2.5,
            ]),
        ],
    ];
}

test('buildShipRequest wraps ShipmentRequest with Shipper + ShipTo + ShipFrom', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest(
        $ctx['shipperName'],
        $ctx['shipFrom'],
        $ctx['shipTo'],
        $ctx['packages'],
        'A1B2C3',
        '03',
        'PDF',
        'ORDER_PUBLIC_ID',
    );

    expect($body['ShipmentRequest']['Request']['RequestOption'])->toBe('nonvalidate');
    expect($body['ShipmentRequest']['Shipment']['Description'])->toContain('ORDER_PUBLIC_ID');
    expect($body['ShipmentRequest']['Shipment']['Shipper']['Name'])->toBe('ACME Shipping');
    expect($body['ShipmentRequest']['Shipment']['Shipper']['ShipperNumber'])->toBe('A1B2C3');
    expect($body['ShipmentRequest']['Shipment']['Shipper']['Address']['PostalCode'])->toBe('94607');
    expect($body['ShipmentRequest']['Shipment']['ShipTo']['Address']['PostalCode'])->toBe('10118');
    expect($body['ShipmentRequest']['Shipment']['ShipFrom']['Address']['PostalCode'])->toBe('94607');
});

test('buildShipRequest uses BillShipper payment with the account number', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03');
    expect($body['ShipmentRequest']['Shipment']['PaymentInformation']['ShipmentCharge']['Type'])->toBe('01');
    expect($body['ShipmentRequest']['Shipment']['PaymentInformation']['ShipmentCharge']['BillShipper']['AccountNumber'])
        ->toBe('A1B2C3');
});

test('buildShipRequest sets Service.Code to the requested UPS service', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '01');
    expect($body['ShipmentRequest']['Shipment']['Service']['Code'])->toBe('01');
});

test('buildShipRequest LabelSpecification LabelImageFormat.Code defaults to PDF', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03');
    expect($body['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'])->toBe('PDF');
});

test('buildShipRequest uppercases the label format and supports ZPL', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03', 'zpl');
    expect($body['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'])->toBe('ZPL');
});

test('buildShipRequest attaches reference number 00 with the order public id', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03', 'PDF', 'order_abc');
    $pkg = $body['ShipmentRequest']['Shipment']['Package'][0];
    expect($pkg['ReferenceNumber'][0]['Code'])->toBe('00');
    expect($pkg['ReferenceNumber'][0]['Value'])->toBe('order_abc');
});

test('buildShipRequest omits DeliveryConfirmation when no signature requested', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03', 'PDF', 'o1', null);
    $pkg  = $body['ShipmentRequest']['Shipment']['Package'][0];
    expect(isset($pkg['PackageServiceOptions']['DeliveryConfirmation']))->toBeFalse();
});

test('buildShipRequest sets DeliveryConfirmation DCISType=2 for standard signature', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03', 'PDF', 'o1', 'standard');
    $pkg  = $body['ShipmentRequest']['Shipment']['Package'][0];
    expect($pkg['PackageServiceOptions']['DeliveryConfirmation']['DCISType'])->toBe('2');
});

test('buildShipRequest sets DeliveryConfirmation DCISType=3 for adult signature', function () {
    $ctx  = upsShipTestContext();
    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03', 'PDF', 'o1', 'adult');
    $pkg  = $body['ShipmentRequest']['Shipment']['Package'][0];
    expect($pkg['PackageServiceOptions']['DeliveryConfirmation']['DCISType'])->toBe('3');
});

test('buildShipRequest supports multi-package shipments', function () {
    $ctx = upsShipTestContext();
    $ctx['packages'][] = UPS::entityToUpsPackage((object) ['length' => 5, 'width' => 5, 'height' => 5, 'weight' => 1]);

    $body = UPS::buildShipRequest($ctx['shipperName'], $ctx['shipFrom'], $ctx['shipTo'], $ctx['packages'], 'A1B2C3', '03');
    expect($body['ShipmentRequest']['Shipment']['Package'])->toHaveCount(2);
});

// ── normalizeShipResponse ────────────────────────────────────────────────

test('normalizeShipResponse extracts tracking number and decodes label binary', function () {
    $label = '%PDF-1.4 fake pdf bytes';
    $resp = [
        'ShipmentResponse' => [
            'ShipmentResults' => [
                'ShipmentIdentificationNumber' => '1Z999AA10123456784',
                'PackageResults' => [
                    [
                        'TrackingNumber' => '1Z999AA10123456784',
                        'ShippingLabel' => [
                            'ImageFormat'  => ['Code' => 'PDF'],
                            'GraphicImage' => base64_encode($label),
                        ],
                    ],
                ],
            ],
        ],
    ];

    $row = UPS::normalizeShipResponse($resp);

    expect($row['tracking_number'])->toBe('1Z999AA10123456784');
    expect($row['shipment_id'])->toBe('1Z999AA10123456784');
    expect($row['label_binary'])->toBe($label);
    expect($row['label_format'])->toBe('PDF');
    expect($row['label_mime'])->toBe('application/pdf');
});

test('normalizeShipResponse derives ZPL mime for ZPL labels', function () {
    $resp = [
        'ShipmentResponse' => [
            'ShipmentResults' => [
                'ShipmentIdentificationNumber' => '1Z000',
                'PackageResults' => [
                    [
                        'TrackingNumber' => '1Z000',
                        'ShippingLabel' => [
                            'ImageFormat'  => ['Code' => 'ZPL'],
                            'GraphicImage' => base64_encode('^XA^XZ'),
                        ],
                    ],
                ],
            ],
        ],
    ];

    $row = UPS::normalizeShipResponse($resp);
    expect($row['label_format'])->toBe('ZPL');
    expect($row['label_mime'])->toBe('application/zpl');
});

test('normalizeShipResponse handles single PackageResults returned as object (not array)', function () {
    $resp = [
        'ShipmentResponse' => [
            'ShipmentResults' => [
                'ShipmentIdentificationNumber' => '1Z000',
                'PackageResults' => [
                    'TrackingNumber' => '1Z000',
                    'ShippingLabel' => [
                        'ImageFormat'  => ['Code' => 'PDF'],
                        'GraphicImage' => base64_encode('bin'),
                    ],
                ],
            ],
        ],
    ];

    $row = UPS::normalizeShipResponse($resp);
    expect($row['tracking_number'])->toBe('1Z000');
    expect($row['label_binary'])->toBe('bin');
});

test('normalizeShipResponse throws when ShipmentResults missing', function () {
    $resp = ['ShipmentResponse' => ['Response' => ['ResponseStatus' => ['Code' => '0']]]];
    expect(fn () => UPS::normalizeShipResponse($resp))->toThrow(RuntimeException::class);
});

test('normalizeShipResponse throws when tracking number missing', function () {
    $resp = ['ShipmentResponse' => ['ShipmentResults' => []]];
    expect(fn () => UPS::normalizeShipResponse($resp))->toThrow(RuntimeException::class);
});

// ── normalizeVoidResponse ────────────────────────────────────────────────

test('normalizeVoidResponse returns true on Status.Code=1 Success', function () {
    $resp = [
        'VoidShipmentResponse' => [
            'SummaryResult' => [
                'Status' => ['Code' => '1', 'Description' => 'Success'],
            ],
        ],
    ];
    expect(UPS::normalizeVoidResponse($resp))->toBeTrue();
});

test('normalizeVoidResponse returns true for success description only', function () {
    $resp = [
        'VoidShipmentResponse' => [
            'SummaryResult' => [
                'Status' => ['Description' => 'Success'],
            ],
        ],
    ];
    expect(UPS::normalizeVoidResponse($resp))->toBeTrue();
});

test('normalizeVoidResponse returns false when Status is absent', function () {
    $resp = ['VoidShipmentResponse' => ['SummaryResult' => []]];
    expect(UPS::normalizeVoidResponse($resp))->toBeFalse();
});

test('normalizeVoidResponse returns false on empty array', function () {
    expect(UPS::normalizeVoidResponse([]))->toBeFalse();
});

test('normalizeVoidResponse returns false on failure status', function () {
    $resp = [
        'VoidShipmentResponse' => [
            'SummaryResult' => [
                'Status' => ['Code' => '0', 'Description' => 'Failed'],
            ],
        ],
    ];
    expect(UPS::normalizeVoidResponse($resp))->toBeFalse();
});
