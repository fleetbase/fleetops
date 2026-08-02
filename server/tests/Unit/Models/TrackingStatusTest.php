<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

function fleetopsTrackingStatusUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

class FleetOpsTrackingStatusInsertFake extends TrackingStatus
{
    public static array $insertedValues = [];
    public static bool $insertResult    = true;

    public static function resetInsertFake(): void
    {
        static::$insertedValues = [];
        static::$insertResult   = true;
    }

    public function getFillable()
    {
        return array_merge(parent::getFillable(), ['meta']);
    }

    protected static function newUuid(): string
    {
        return 'status-uuid';
    }

    protected static function newPublicId(): string
    {
        return 'status_public';
    }

    protected static function currentTimestamp(): string
    {
        return '2026-08-03 10:11:12';
    }

    protected static function insertTrackingStatus(array $values): bool
    {
        static::$insertedValues[] = $values;

        return static::$insertResult;
    }
}

beforeEach(function () {
    FleetOpsTrackingStatusInsertFake::resetInsertFake();
    Carbon::setTestNow();
});

test('tracking status relationships use tracking number and proof models', function () {
    fleetopsTrackingStatusUseInMemoryRelationConnection();

    $status = new TrackingStatus();

    expect($status->trackingNumber()->getRelated())->toBeInstanceOf(TrackingNumber::class)
        ->and($status->proof()->getRelated())->toBeInstanceOf(Proof::class);
});

test('tracking status insert filters values and applies defaults without tracking number', function () {
    $uuid = FleetOpsTrackingStatusInsertFake::insertGetUuid([
        'status'      => 'arrived',
        'code'        => 'ARRIVED',
        'complete'    => false,
        'meta'        => ['source' => 'scanner'],
        'not_allowed' => 'ignored',
    ]);

    expect($uuid)->toBe('status-uuid')
        ->and(FleetOpsTrackingStatusInsertFake::$insertedValues)->toHaveCount(1);

    $values = FleetOpsTrackingStatusInsertFake::$insertedValues[0];

    expect($values)->toMatchArray([
        'uuid'         => 'status-uuid',
        'public_id'    => 'status_public',
        '_key'         => 'console',
        'created_at'   => '2026-08-03 10:11:12',
        'company_uuid' => null,
        'status'       => 'arrived',
        'code'         => 'ARRIVED',
        'complete'     => false,
        'meta'         => '{"source":"scanner"}',
    ])->and($values)->not->toHaveKey('not_allowed');
});

test('tracking status insert derives initial status details from tracking number', function () {
    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes([
        'uuid'       => 'tracking-number-uuid',
        'owner_type' => Order::class,
    ], true);

    $uuid = FleetOpsTrackingStatusInsertFake::insertGetUuid([
        'details' => 'will be replaced',
    ], $trackingNumber);

    expect($uuid)->toBe('status-uuid');

    $values = FleetOpsTrackingStatusInsertFake::$insertedValues[0];

    expect($values)->toMatchArray([
        'tracking_number_uuid' => 'tracking-number-uuid',
        'status'               => 'Order Created',
        'details'              => 'New order created.',
    ]);
});

test('tracking status insert returns false when the insert fails', function () {
    FleetOpsTrackingStatusInsertFake::$insertResult = false;

    expect(FleetOpsTrackingStatusInsertFake::insertGetUuid(['status' => 'failed']))->toBeFalse()
        ->and(FleetOpsTrackingStatusInsertFake::$insertedValues)->toHaveCount(1);
});
