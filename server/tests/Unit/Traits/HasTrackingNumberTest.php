<?php

use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
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

function fleetopsTrackingNumberUnitUseDbRaw(): void
{
    app()->instance('db', new class {
        public function raw(mixed $value): Expression
        {
            return new Expression($value);
        }
    });

    DB::clearResolvedInstance('db');
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
    fleetopsTrackingNumberUnitUseDbRaw();

    $host = new FleetOpsTrackingNumberUnitHostFake();

    expect(fn () => $host->getLocationAsPoint([]))->toThrow(ArgumentCountError::class)
        ->and(fleetopsTrackingNumberUnitExpressionValue($host->getLocationAsPoint(new Point(1.25, 103.75))))->toBe("(ST_PointFromText('POINT(103.75 1.25)', 0, 'axis-order=long-lat'))")
        ->and(fleetopsTrackingNumberUnitExpressionValue($host->getLocationAsPoint([25.2048, 55.2708])))->toBe("(ST_PointFromText('POINT(55.2708 25.2048)', 0, 'axis-order=long-lat'))")
        ->and(fleetopsTrackingNumberUnitExpressionValue($host->getLocationAsPoint(null)))->toBe("(ST_PointFromText('POINT(0 0)', 0, 'axis-order=long-lat'))");
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

test('tracking number activity templates return unchanged without placeholders or target model', function () {
    $host       = new FleetOpsTrackingNumberUnitHostFake();
    $reflection = new ReflectionMethod($host, 'resolveActivityTemplateString');
    $reflection->setAccessible(true);

    expect($reflection->invoke($host, 'Package picked up'))->toBe('Package picked up')
        ->and($reflection->invoke($host, 'Package for {order.public_id}'))->toBe('Package for {order.public_id}');
});
