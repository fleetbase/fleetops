<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

class FleetOpsTrackingNumberUnitHostFake extends Model
{
    use HasTrackingNumber;

    protected $fillable = ['tracking_number_uuid', 'status'];

    public int $saveCalls = 0;

    public function save(array $options = [])
    {
        $this->saveCalls++;

        return true;
    }
}

class FleetOpsTrackingNumberUnitActivityHostFake extends FleetOpsTrackingNumberUnitHostFake
{
    public int $flushCalls = 0;

    public function getLocationAsPoint($location)
    {
        return 'POINT(103.8 1.3)';
    }

    public function flushAttributesCache(): void
    {
        $this->flushCalls++;
    }
}

class FleetOpsTrackingNumberUnitTrackingNumberFake extends TrackingNumber
{
    public int $flushCalls = 0;

    public function flushAttributesCache(): bool
    {
        $this->flushCalls++;

        return true;
    }
}

class FleetOpsTrackingNumberUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

class FleetOpsTrackingNumberUnitGuardedHostFake extends FleetOpsTrackingNumberUnitHostFake
{
    protected $fillable = ['status'];
}

class FleetOpsTrackingNumberUnitPayloadHostFake extends FleetOpsTrackingNumberUnitHostFake
{
    public array $loadedRelations = [];

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

    public function payload()
    {
        return null;
    }
}

class FleetOpsTrackingNumberUnitPayloadFake extends Payload
{
    public function getPickupRegion(): string
    {
        return 'AE';
    }

    public function getPickupLocation()
    {
        return new Point(25.2048, 55.2708);
    }
}

function fleetopsTrackingNumberUnitUseDbRaw()
{
    $previousDbBinding = app()->bound('db') ? app('db') : null;

    app()->instance('db', new class {
        public function raw(mixed $value): Expression
        {
            return new Expression($value);
        }
    });

    DB::clearResolvedInstance('db');

    return $previousDbBinding;
}

function fleetopsTrackingNumberUnitRestoreDb($previousDbBinding): void
{
    if ($previousDbBinding) {
        app()->instance('db', $previousDbBinding);
    } else {
        app()->forgetInstance('db');
    }

    DB::clearResolvedInstance('db');
}

function fleetopsTrackingNumberUnitUseActivityDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->getPdo()->sqliteCreateFunction('ST_GeomFromText', fn ($value) => $value, 3);
    $connection->statement('create table tracking_statuses (id integer primary key autoincrement, uuid varchar(64) null, public_id varchar(64) null, _key varchar(64) null, company_uuid varchar(64) null, tracking_number_uuid varchar(64) null, proof_uuid varchar(64) null, status varchar(255) null, details text null, location text null, code varchar(64) null, complete integer null, created_at datetime null, updated_at datetime null, deleted_at datetime null)');
    $connection->statement('create table proofs (id integer primary key autoincrement, uuid varchar(64), public_id varchar(64) null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsTrackingNumberUnitDatabaseProbe($connection));
    DB::clearResolvedInstance('db');

    return $connection;
}

function fleetopsTrackingNumberUnitExpressionValue(Expression $expression): string
{
    $value = new ReflectionProperty($expression, 'value');
    $value->setAccessible(true);

    return $value->getValue($expression);
}

test('tracking number can only be set on fillable empty hosts', function () {
    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tracking-uuid'], true);

    $host = new FleetOpsTrackingNumberUnitHostFake();

    expect($host->setTrackingNumber($trackingNumber))->toBe($host)
        ->and($host->tracking_number_uuid)->toBe('tracking-uuid')
        ->and($host->trackingNumber)->toBe($trackingNumber)
        ->and($host->saveCalls)->toBe(1);

    $existing = new FleetOpsTrackingNumberUnitHostFake(['tracking_number_uuid' => 'existing-tracking']);

    expect($existing->setTrackingNumber($trackingNumber))->toBe($existing)
        ->and($existing->tracking_number_uuid)->toBe('existing-tracking')
        ->and($existing->relationLoaded('trackingNumber'))->toBeFalse()
        ->and($existing->saveCalls)->toBe(0);

    $guarded = new FleetOpsTrackingNumberUnitGuardedHostFake();

    expect($guarded->setTrackingNumber($trackingNumber))->toBe($guarded)
        ->and($guarded->tracking_number_uuid)->toBeNull()
        ->and($guarded->relationLoaded('trackingNumber'))->toBeFalse()
        ->and($guarded->saveCalls)->toBe(0);
});

test('tracking number location conversion accepts points arrays and empty values', function () {
    $previousDbBinding = fleetopsTrackingNumberUnitUseDbRaw();

    try {
        $host = new FleetOpsTrackingNumberUnitHostFake();

        expect(fn () => $host->getLocationAsPoint([]))->toThrow(ArgumentCountError::class)
            ->and(fleetopsTrackingNumberUnitExpressionValue($host->getLocationAsPoint(new Point(1.25, 103.75))))->toBe("(ST_PointFromText('POINT(103.75 1.25)', 0, 'axis-order=long-lat'))")
            ->and(fleetopsTrackingNumberUnitExpressionValue($host->getLocationAsPoint([25.2048, 55.2708])))->toBe("(ST_PointFromText('POINT(55.2708 25.2048)', 0, 'axis-order=long-lat'))")
            ->and(fleetopsTrackingNumberUnitExpressionValue($host->getLocationAsPoint(null)))->toBe("(ST_PointFromText('POINT(0 0)', 0, 'axis-order=long-lat'))");
    } finally {
        fleetopsTrackingNumberUnitRestoreDb($previousDbBinding);
    }
});

test('tracking number pickup metadata falls back or delegates to payload', function () {
    $fallback = new FleetOpsTrackingNumberUnitHostFake();

    expect($fallback->getPickupRegion())->toBe('SG')
        ->and($fallback->getPickupLocation())->toEqual(new Point(0, 0));

    $payload = new FleetOpsTrackingNumberUnitPayloadFake();
    $host    = new FleetOpsTrackingNumberUnitPayloadHostFake();
    $host->setRelation('payload', $payload);

    expect($host->getPickupRegion())->toBe('AE')
        ->and($host->getPickupLocation())->toEqual(new Point(25.2048, 55.2708))
        ->and($host->loadedRelations)->toBe([
            ['payload'],
            ['payload'],
        ]);
});

test('tracking number status mutation can be saved or kept in memory', function () {
    $host = new FleetOpsTrackingNumberUnitHostFake();

    expect($host->setStatus('in_transit'))->toBe($host)
        ->and($host->status)->toBe('in_transit')
        ->and($host->saveCalls)->toBe(1);

    $host->setStatus('completed', false);

    expect($host->status)->toBe('completed')
        ->and($host->saveCalls)->toBe(1);
});

test('tracking number proof resolution accepts proof instances and empty values', function () {
    $proof = new Proof();
    $proof->setRawAttributes(['uuid' => 'proof-uuid'], true);

    expect(FleetOpsTrackingNumberUnitHostFake::resolveProof($proof))->toBe($proof)
        ->and(FleetOpsTrackingNumberUnitHostFake::resolveProof(null))->toBeNull()
        ->and(FleetOpsTrackingNumberUnitHostFake::resolveProof(['uuid' => 'not-a-proof']))->toBeNull();
});

test('tracking number activity creation and insertion persists statuses and flushes caches', function () {
    $connection = fleetopsTrackingNumberUnitUseActivityDatabase();
    $proof      = new Proof();
    $proof->setRawAttributes(['uuid' => 'proof-uuid'], true);

    $trackingNumber = new FleetOpsTrackingNumberUnitTrackingNumberFake();
    $trackingNumber->setRawAttributes(['uuid' => 'tracking-number-uuid'], true);

    $host = new FleetOpsTrackingNumberUnitActivityHostFake();
    $host->setRawAttributes([
        'company_uuid'         => 'company-uuid',
        'tracking_number_uuid' => 'tracking-number-uuid',
    ], true);
    $host->setRelation('trackingNumber', $trackingNumber);

    $created = $host->createActivity(new Activity([
        'code'     => 'arrived @ hub',
        'status'   => 'arrived at hub',
        'details'  => 'Driver arrived',
        'complete' => false,
    ]), [1.3, 103.8], $proof);

    expect($created)->toBeInstanceOf(TrackingStatus::class)
        ->and($created->tracking_number_uuid)->toBe('tracking-number-uuid')
        ->and($created->proof_uuid)->toBe('proof-uuid')
        ->and($created->status)->toBe('Arrived At Hub')
        ->and($created->details)->toBe('Driver arrived')
        ->and($created->code)->toBe('ARRIVED__HUB')
        ->and($created->isComplete())->toBeFalse()
        ->and($host->flushCalls)->toBe(1)
        ->and($trackingNumber->flushCalls)->toBe(1);

    $insertedUuid = $host->insertActivity(new Activity([
        'code'     => 'completed stop',
        'status'   => 'completed stop',
        'details'  => 'Stop completed',
        'complete' => true,
    ]), null, $proof);

    $inserted = $connection->table('tracking_statuses')->where('uuid', $insertedUuid)->first();

    expect($insertedUuid)->toBeString()
        ->and($inserted)->not->toBeNull()
        ->and($inserted->tracking_number_uuid)->toBe('tracking-number-uuid')
        ->and($inserted->proof_uuid)->toBe('proof-uuid')
        ->and($inserted->status)->toBe('completed stop')
        ->and($inserted->details)->toBe('Stop completed')
        ->and($inserted->code)->toBe('COMPLETED_STOP')
        ->and((bool) $inserted->complete)->toBeTrue()
        ->and($host->flushCalls)->toBe(2)
        ->and($trackingNumber->flushCalls)->toBe(2);
});

test('tracking number activity templates return unchanged without placeholders or target model', function () {
    $host       = new FleetOpsTrackingNumberUnitHostFake();
    $reflection = new ReflectionMethod($host, 'resolveActivityTemplateString');
    $reflection->setAccessible(true);

    expect($reflection->invoke($host, 'Package picked up'))->toBe('Package picked up')
        ->and($reflection->invoke($host, 'Package for {order.public_id}'))->toBe('Package for {order.public_id}');
});
