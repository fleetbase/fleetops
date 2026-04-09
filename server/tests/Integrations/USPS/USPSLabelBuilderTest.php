<?php

use Fleetbase\FleetOps\Integrations\USPS\USPS;

// ── buildLabelRequest ────────────────────────────────────────────────────

test('buildLabelRequest assembles fromAddress/toAddress/packageDescription', function () {
    $from = USPS::placeToUspsAddress((object) [
        'street1' => '1 Warehouse Way', 'city' => 'Oakland', 'province' => 'CA', 'postal_code' => '94607',
    ]);
    $to = USPS::placeToUspsAddress((object) [
        'street1' => '350 5th Ave', 'city' => 'New York', 'province' => 'NY', 'postal_code' => '10118',
    ]);
    $parcel = USPS::entityToUspsParcel((object) ['length' => 12, 'width' => 9, 'height' => 3, 'weight' => 2.5]);

    $body = USPS::buildLabelRequest('ACME Shipper', $from, 'Acme Customer', $to, $parcel, 'PRIORITY_MAIL', 'order_abc');

    expect($body['fromAddress']['streetAddress'])->toBe('1 Warehouse Way');
    expect($body['fromAddress']['ZIPCode'])->toBe('94607');
    expect($body['toAddress']['streetAddress'])->toBe('350 5th Ave');
    expect($body['toAddress']['ZIPCode'])->toBe('10118');
    expect($body['packageDescription']['mailClass'])->toBe('PRIORITY_MAIL');
    expect($body['packageDescription']['weight'])->toBe(2.5);
    expect($body['packageDescription']['length'])->toBe(12.0);
    expect($body['packageDescription']['width'])->toBe(9.0);
    expect($body['packageDescription']['height'])->toBe(3.0);
});

test('buildLabelRequest attaches shipper and recipient names', function () {
    $from = USPS::placeToUspsAddress((object) ['postal_code' => '94607']);
    $to   = USPS::placeToUspsAddress((object) ['postal_code' => '10118']);
    $parcel = USPS::entityToUspsParcel((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]);

    $body = USPS::buildLabelRequest('ACME', $from, 'Customer A', $to, $parcel, 'PRIORITY_MAIL');
    expect($body['fromAddress']['firstName'])->toBe('ACME');
    expect($body['toAddress']['firstName'])->toBe('Customer A');
});

test('buildLabelRequest attaches a customer reference when an order public_id is provided', function () {
    $from = USPS::placeToUspsAddress((object) ['postal_code' => '94607']);
    $to   = USPS::placeToUspsAddress((object) ['postal_code' => '10118']);
    $parcel = USPS::entityToUspsParcel((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]);

    $body = USPS::buildLabelRequest('A', $from, 'B', $to, $parcel, 'PRIORITY_MAIL', 'order_xyz');
    expect($body['packageDescription']['customerReference'])->toBe('order_xyz');
});

test('buildLabelRequest always sets imageType to PDF (no ZPL path)', function () {
    $from = USPS::placeToUspsAddress((object) ['postal_code' => '94607']);
    $to   = USPS::placeToUspsAddress((object) ['postal_code' => '10118']);
    $parcel = USPS::entityToUspsParcel((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]);

    $body = USPS::buildLabelRequest('A', $from, 'B', $to, $parcel, 'PRIORITY_MAIL');
    expect($body['imageInfo']['imageType'])->toBe('PDF');
});

// ── normalizeLabelResponse ───────────────────────────────────────────────

test('normalizeLabelResponse extracts trackingNumber and decodes labelImage', function () {
    $bytes = '%PDF-1.4 fake usps label';
    $resp = [
        'trackingNumber' => '9400111202555999999999',
        'labelImage'     => base64_encode($bytes),
        'labelMetadata'  => ['labelImageFormat' => 'PDF'],
    ];

    $row = USPS::normalizeLabelResponse($resp);

    expect($row['tracking_number'])->toBe('9400111202555999999999');
    expect($row['label_binary'])->toBe($bytes);
    expect($row['label_format'])->toBe('PDF');
    expect($row['label_mime'])->toBe('application/pdf');
});

test('normalizeLabelResponse defaults labelImageFormat to PDF when absent', function () {
    $resp = [
        'trackingNumber' => '9400',
        'labelImage'     => base64_encode('bin'),
    ];
    $row = USPS::normalizeLabelResponse($resp);
    expect($row['label_format'])->toBe('PDF');
    expect($row['label_mime'])->toBe('application/pdf');
});

test('normalizeLabelResponse forces PDF even if response reports a non-PDF format', function () {
    // USPS v3 does not issue ZPL labels; enforce PDF-only
    // semantics regardless of what the response carries.
    $resp = [
        'trackingNumber' => '9400',
        'labelImage'     => base64_encode('bin'),
        'labelMetadata'  => ['labelImageFormat' => 'TIFF'],
    ];
    $row = USPS::normalizeLabelResponse($resp);
    expect($row['label_format'])->toBe('PDF');
    expect($row['label_mime'])->toBe('application/pdf');
});

test('normalizeLabelResponse throws when trackingNumber is missing', function () {
    expect(fn () => USPS::normalizeLabelResponse(['labelImage' => base64_encode('x')]))
        ->toThrow(RuntimeException::class);
});

test('normalizeLabelResponse throws when labelImage is missing', function () {
    expect(fn () => USPS::normalizeLabelResponse(['trackingNumber' => '9400']))
        ->toThrow(RuntimeException::class);
});

// ── normalizeTrackingResponse ────────────────────────────────────────────

test('normalizeTrackingResponse returns status + carrier + events', function () {
    $resp = [
        'trackingEvents' => [
            ['eventType' => 'ACCEPTED', 'eventTimestamp' => '2026-04-07T10:00:00Z', 'eventCity' => 'Oakland'],
            ['eventType' => 'IN_TRANSIT', 'eventTimestamp' => '2026-04-08T08:00:00Z', 'eventCity' => 'Reno'],
            ['eventType' => 'DELIVERED', 'eventTimestamp' => '2026-04-09T14:22:00Z', 'eventCity' => 'New York'],
        ],
    ];
    $result = USPS::normalizeTrackingResponse($resp);
    expect($result['status'])->toBe('DELIVERED'); // last event's code
    expect($result['carrier'])->toBe('USPS');
    expect($result['events'])->toHaveCount(3);
    expect($result['events'][0]['code'])->toBe('ACCEPTED');
    expect($result['events'][2]['code'])->toBe('DELIVERED');
});

test('normalizeTrackingResponse maps ALERT event type to EXCEPTION', function () {
    $resp = ['trackingEvents' => [['eventType' => 'ALERT', 'eventTimestamp' => '2026-04-07T10:00:00Z']]];
    $result = USPS::normalizeTrackingResponse($resp);
    expect($result['events'][0]['code'])->toBe('EXCEPTION');
    expect($result['status'])->toBe('EXCEPTION');
});

test('normalizeTrackingResponse preserves known codes verbatim (DELIVERED, IN_TRANSIT, etc)', function () {
    $resp = ['trackingEvents' => [
        ['eventType' => 'ACCEPTED', 'eventTimestamp' => 't'],
        ['eventType' => 'IN_TRANSIT', 'eventTimestamp' => 't'],
        ['eventType' => 'OUT_FOR_DELIVERY', 'eventTimestamp' => 't'],
        ['eventType' => 'ARRIVAL_AT_POST_OFFICE', 'eventTimestamp' => 't'],
        ['eventType' => 'RETURN_TO_SENDER', 'eventTimestamp' => 't'],
    ]];
    $result = USPS::normalizeTrackingResponse($resp);
    $codes = array_column($result['events'], 'code');
    expect($codes)->toBe(['ACCEPTED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'ARRIVAL_AT_POST_OFFICE', 'RETURN_TO_SENDER']);
});

test('normalizeTrackingResponse uppercases lowercase event codes', function () {
    $resp = ['trackingEvents' => [['eventType' => 'delivered', 'eventTimestamp' => 't']]];
    $result = USPS::normalizeTrackingResponse($resp);
    expect($result['events'][0]['code'])->toBe('DELIVERED');
});

test('normalizeTrackingResponse returns UNKNOWN status + empty events when events missing', function () {
    expect(USPS::normalizeTrackingResponse([]))->toBe([
        'status'  => 'UNKNOWN',
        'carrier' => 'USPS',
        'events'  => [],
    ]);
});

// ── normalizeVoidResponse ────────────────────────────────────────────────

test('normalizeVoidResponse returns true when refundStatus is APPROVED', function () {
    expect(USPS::normalizeVoidResponse(['refundStatus' => 'APPROVED']))->toBeTrue();
});

test('normalizeVoidResponse is case-insensitive on status', function () {
    expect(USPS::normalizeVoidResponse(['refundStatus' => 'approved']))->toBeTrue();
});

test('normalizeVoidResponse returns false on PENDING', function () {
    expect(USPS::normalizeVoidResponse(['refundStatus' => 'PENDING']))->toBeFalse();
});

test('normalizeVoidResponse returns false on empty response', function () {
    expect(USPS::normalizeVoidResponse([]))->toBeFalse();
});
