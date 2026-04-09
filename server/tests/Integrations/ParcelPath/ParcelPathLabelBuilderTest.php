<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;

// ── buildLabelPurchaseRequest ────────────────────────────────────────────

test('buildLabelPurchaseRequest builds request with rate_id and uppercase label_format', function () {
    $body = ParcelPath::buildLabelPurchaseRequest('rate_abc', 'PDF');
    expect($body)->toBe(['rate_id' => 'rate_abc', 'label_format' => 'PDF']);
});

test('buildLabelPurchaseRequest defaults label_format to PDF', function () {
    $body = ParcelPath::buildLabelPurchaseRequest('rate_abc');
    expect($body['label_format'])->toBe('PDF');
});

test('buildLabelPurchaseRequest uppercases pdf to PDF', function () {
    expect(ParcelPath::buildLabelPurchaseRequest('r1', 'pdf')['label_format'])->toBe('PDF');
});

test('buildLabelPurchaseRequest uppercases zpl to ZPL', function () {
    expect(ParcelPath::buildLabelPurchaseRequest('r1', 'zpl')['label_format'])->toBe('ZPL');
});

test('buildLabelPurchaseRequest throws InvalidArgumentException on null rate_id', function () {
    ParcelPath::buildLabelPurchaseRequest(null);
})->throws(\InvalidArgumentException::class, 'rate_id required');

test('buildLabelPurchaseRequest throws InvalidArgumentException on empty rate_id', function () {
    ParcelPath::buildLabelPurchaseRequest('');
})->throws(\InvalidArgumentException::class, 'rate_id required');

// ── normalizeLabelResponse ───────────────────────────────────────────────

test('normalizeLabelResponse extracts tracking_number, carrier, parcelpath_shipment_id', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number'         => '1Z999AA10123456784',
        'carrier'                 => 'UPS',
        'label_data'              => base64_encode('PDFBYTES'),
        'label_format'            => 'PDF',
        'parcelpath_shipment_id'  => 'pp_ship_001',
    ]);
    expect($row['tracking_number'])->toBe('1Z999AA10123456784');
    expect($row['carrier'])->toBe('UPS');
    expect($row['parcelpath_shipment_id'])->toBe('pp_ship_001');
});

test('normalizeLabelResponse base64-decodes label_data into label_binary', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number' => 'T1',
        'label_data'      => base64_encode('HELLOPDF'),
    ]);
    expect($row['label_binary'])->toBe('HELLOPDF');
});

test('normalizeLabelResponse derives application/pdf mime for PDF format', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number' => 'T1',
        'label_data'      => base64_encode('x'),
        'label_format'    => 'PDF',
    ]);
    expect($row['label_mime'])->toBe('application/pdf');
    expect($row['label_format'])->toBe('PDF');
});

test('normalizeLabelResponse derives application/zpl mime for ZPL format', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number' => 'T1',
        'label_data'      => base64_encode('x'),
        'label_format'    => 'zpl',
    ]);
    expect($row['label_mime'])->toBe('application/zpl');
    expect($row['label_format'])->toBe('ZPL');
});

test('normalizeLabelResponse defaults label_format to PDF when missing', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number' => 'T1',
        'label_data'      => base64_encode('x'),
    ]);
    expect($row['label_format'])->toBe('PDF');
    expect($row['label_mime'])->toBe('application/pdf');
});

test('normalizeLabelResponse defaults insurance to empty array when missing', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number' => 'T1',
        'label_data'      => base64_encode('x'),
    ]);
    expect($row['insurance'])->toBe([]);
});

test('normalizeLabelResponse preserves insurance payload when present', function () {
    $row = ParcelPath::normalizeLabelResponse([
        'tracking_number' => 'T1',
        'label_data'      => base64_encode('x'),
        'insurance'       => ['purchased' => true, 'policy_id' => 'pol_123'],
    ]);
    expect($row['insurance'])->toBe(['purchased' => true, 'policy_id' => 'pol_123']);
});

test('normalizeLabelResponse throws RuntimeException if tracking_number missing', function () {
    ParcelPath::normalizeLabelResponse(['label_data' => base64_encode('x')]);
})->throws(\RuntimeException::class, 'invalid label response');

test('normalizeLabelResponse throws RuntimeException if label_data missing', function () {
    ParcelPath::normalizeLabelResponse(['tracking_number' => 'T1']);
})->throws(\RuntimeException::class, 'invalid label response');
