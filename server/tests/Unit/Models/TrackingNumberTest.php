<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Str;

class FleetOpsTrackingNumberInsertFake extends TrackingNumber
{
    public static array $insertedValues  = [];
    public static array $createdStatuses = [];
    public static array $statusUpdates   = [];
    public static array $ownerUpdates    = [];
    public static bool $insertResult     = true;

    public static function resetInsertFake(): void
    {
        static::$insertedValues  = [];
        static::$createdStatuses = [];
        static::$statusUpdates   = [];
        static::$ownerUpdates    = [];
        static::$insertResult    = true;
    }

    public function getFillable()
    {
        return array_merge(parent::getFillable(), ['meta', 'location']);
    }

    protected static function newUuid(): string
    {
        return 'tracking-uuid';
    }

    protected static function newPublicId(): string
    {
        return 'track_public';
    }

    protected static function currentTimestamp(): string
    {
        return '2026-08-04 10:20:30';
    }

    protected static function newTrackingNumber(string $region): string
    {
        return 'TRACK-' . $region;
    }

    protected static function newBarcode(string $value, string $type): string
    {
        return $type . ':' . $value;
    }

    protected static function insertTrackingNumber(array $values): bool
    {
        static::$insertedValues[] = $values;

        return static::$insertResult;
    }

    protected static function createInitialTrackingStatus(array $values): string
    {
        static::$createdStatuses[] = $values;

        return 'status-uuid';
    }

    protected static function updateTrackingStatusUuid(string $uuid, mixed $trackingStatusId): void
    {
        static::$statusUpdates[] = [$uuid, $trackingStatusId];
    }

    protected static function updateOwnerStatusColumn(Fleetbase\Models\Model $owner, string $status): void
    {
        static::$ownerUpdates[] = [$owner->getTable(), $owner->uuid, $status];
    }

    protected static function ownerHasStatusColumn(Fleetbase\Models\Model $owner): bool
    {
        return true;
    }
}

beforeEach(function () {
    FleetOpsTrackingNumberInsertFake::resetInsertFake();
});

test('tracking number insert filters values and creates initial owner status', function () {
    $owner = new Order();
    $owner->setRawAttributes([
        'uuid'   => 'order-uuid',
        'status' => 'pending',
    ], true);

    $uuid = FleetOpsTrackingNumberInsertFake::insertGetUuid([
        'region'      => 'AE',
        'meta'        => ['source' => 'api'],
        'location'    => 'POINT(1 2)',
        'not_allowed' => 'ignored',
    ], $owner);

    expect($uuid)->toBe('tracking-uuid')
        ->and(FleetOpsTrackingNumberInsertFake::$insertedValues)->toHaveCount(1);

    $inserted = FleetOpsTrackingNumberInsertFake::$insertedValues[0];

    expect($inserted)->toMatchArray([
        'uuid'            => 'tracking-uuid',
        'public_id'       => 'track_public',
        '_key'            => 'console',
        'created_at'      => '2026-08-04 10:20:30',
        'company_uuid'    => null,
        'owner_uuid'      => 'order-uuid',
        'owner_type'      => Utils::getMutationType($owner),
        'tracking_number' => 'TRACK-AE',
        'qr_code'         => 'QRCODE:order-uuid',
        'barcode'         => 'PDF417:order-uuid',
        'meta'            => '{"source":"api"}',
    ])->and($inserted)->not->toHaveKey('not_allowed');

    $ownerTypeName = class_basename($inserted['owner_type']);

    expect(FleetOpsTrackingNumberInsertFake::$createdStatuses)->toBe([
        [
            'tracking_number_uuid' => 'tracking-uuid',
            'status'               => Str::title($ownerTypeName . ' created'),
            'details'              => 'New ' . Str::lower($ownerTypeName) . ' created.',
            'location'             => 'POINT(1 2)',
            'code'                 => 'CREATED',
        ],
    ])->and(FleetOpsTrackingNumberInsertFake::$statusUpdates)->toBe([
        ['tracking-uuid', 'status-uuid'],
    ])->and(FleetOpsTrackingNumberInsertFake::$ownerUpdates)->toBe([
        ['orders', 'order-uuid', 'created'],
    ]);
});

test('tracking number insert defaults region location and skips side effects when insert fails', function () {
    FleetOpsTrackingNumberInsertFake::$insertResult = false;

    $uuid = FleetOpsTrackingNumberInsertFake::insertGetUuid([
        'region' => 'SG',
    ]);

    expect($uuid)->toBeFalse()
        ->and(FleetOpsTrackingNumberInsertFake::$insertedValues[0])->toMatchArray([
            'uuid'            => 'tracking-uuid',
            'tracking_number' => 'TRACK-SG',
            'qr_code'         => 'QRCODE:',
            'barcode'         => 'PDF417:',
        ])
        ->and(FleetOpsTrackingNumberInsertFake::$createdStatuses)->toBe([])
        ->and(FleetOpsTrackingNumberInsertFake::$statusUpdates)->toBe([])
        ->and(FleetOpsTrackingNumberInsertFake::$ownerUpdates)->toBe([]);
});
