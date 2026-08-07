<?php

use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Traits\HasTrackingNumber;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;

class FleetOpsTrackingNumberTraitHostFake extends Model
{
    use HasTrackingNumber;

    protected $fillable = ['tracking_number_uuid'];

    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsTrackingNumberPayloadHostFake extends FleetOpsTrackingNumberTraitHostFake
{
    public array $loaded = [];

    public function load($relations): static
    {
        $this->loaded = is_array($relations) ? $relations : func_get_args();

        return $this;
    }

    public function payload(): void
    {
    }
}

function fleetopsTrackingExpressionValue(Expression $expression): string
{
    return method_exists($expression, 'getValue') ? $expression->getValue(new Grammar()) : (string) $expression;
}

test('tracking number trait handles assignment status location and pickup fallbacks', function () {
    app()->instance('db', new class {
        public function raw(string $value): Expression
        {
            return new Expression($value);
        }
    });

    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tracking-uuid'], true);

    $host = new FleetOpsTrackingNumberTraitHostFake();
    $host->setTrackingNumber($trackingNumber);

    $filled = new FleetOpsTrackingNumberTraitHostFake();
    $filled->setRawAttributes(['tracking_number_uuid' => 'existing-tracking'], true);
    $filled->setTrackingNumber($trackingNumber);

    expect($host->tracking_number_uuid)->toBe('tracking-uuid')
        ->and($host->trackingNumber)->toBe($trackingNumber)
        ->and($host->saved)->toBeTrue()
        ->and($filled->tracking_number_uuid)->toBe('existing-tracking')
        ->and($filled->saved)->toBeFalse()
        ->and($host->setStatus('dispatched', false))->toBe($host)
        ->and($host->status)->toBe('dispatched')
        ->and($host->getPickupRegion())->toBe('SG')
        ->and($host->getPickupLocation())->toBeInstanceOf(Point::class)
        ->and(fleetopsTrackingExpressionValue($host->getLocationAsPoint(null)))->toContain('POINT(0 0)')
        ->and(fleetopsTrackingExpressionValue($host->getLocationAsPoint([1.31, 103.82])))->toContain('POINT(103.82 1.31)');
});

test('tracking number trait delegates pickup data to payload relations when present', function () {
    $payload = new class(new Point(3.13, 101.68)) {
        public function __construct(public Point $location)
        {
        }

        public function getPickupRegion(): string
        {
            return 'MY';
        }

        public function getPickupLocation(): Point
        {
            return $this->location;
        }
    };

    $host          = new FleetOpsTrackingNumberPayloadHostFake();
    $host->payload = $payload;

    $proof = new Proof();

    expect($host->getPickupRegion())->toBe('MY')
        ->and($host->getPickupLocation())->toBe($payload->getPickupLocation())
        ->and($host->loaded)->toBe(['payload'])
        ->and(FleetOpsTrackingNumberTraitHostFake::resolveProof($proof))->toBe($proof)
        ->and(FleetOpsTrackingNumberTraitHostFake::resolveProof(null))->toBeNull();
});
