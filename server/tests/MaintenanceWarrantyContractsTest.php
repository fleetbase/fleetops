<?php

use Fleetbase\FleetOps\Models\Asset;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

class FleetOpsUpdatingMaintenanceScheduleFake extends MaintenanceSchedule
{
    public array $updates = [];

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsUpdatingWarrantyFake extends Warranty
{
    public array $updates = [];

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return false;
    }
}

test('maintenance schedule due checks reset lifecycle and import mapping are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

    $schedule = new FleetOpsUpdatingMaintenanceScheduleFake([
        'status'                     => 'active',
        'interval_value'             => 30,
        'interval_unit'              => 'days',
        'interval_distance'          => 5000,
        'interval_engine_hours'      => 250,
        'last_service_odometer'      => 10000,
        'last_service_engine_hours'  => 400,
        'last_service_date'          => Carbon::parse('2025-12-01'),
        'next_due_date'              => Carbon::parse('2026-01-10'),
        'next_due_odometer'          => 15000,
        'next_due_engine_hours'      => 650,
    ]);

    expect($schedule->isDue(null, null, Carbon::parse('2026-01-11')))->toBeTrue()
        ->and($schedule->isDue(15000, null, Carbon::parse('2026-01-01')))->toBeTrue()
        ->and($schedule->isDue(null, 650, Carbon::parse('2026-01-01')))->toBeTrue();

    $schedule->forceFill([
        'next_due_date'         => Carbon::parse('2026-02-10'),
        'next_due_odometer'     => 20000,
        'next_due_engine_hours' => 900,
    ]);

    expect($schedule->isDue(12000, 500, Carbon::parse('2026-01-15')))->toBeFalse();

    $schedule->status = 'paused';
    expect($schedule->isDue(25000, 1000, Carbon::parse('2026-03-01')))->toBeFalse();

    $schedule->resetAfterCompletion(16000, 700, Carbon::parse('2026-01-20'));
    expect($schedule->updates[0]['last_service_odometer'])->toBe(16000)
        ->and($schedule->updates[0]['last_service_engine_hours'])->toBe(700)
        ->and($schedule->updates[0]['next_due_date']->toDateString())->toBe('2026-02-19')
        ->and($schedule->updates[0]['next_due_odometer'])->toBe(21000)
        ->and($schedule->updates[0]['next_due_engine_hours'])->toBe(950)
        ->and($schedule->pause())->toBeTrue()
        ->and($schedule->resume())->toBeTrue()
        ->and($schedule->complete())->toBeTrue();

    Carbon::setTestNow();
});

test('warranty accessors coverage limits and transfer guards are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

    $warranty = new FleetOpsUpdatingWarrantyFake([
        'subject_type'  => Asset::class,
        'subject_uuid'  => 'old-asset-uuid',
        'provider'      => 'Warranty Co',
        'start_date'    => Carbon::parse('2026-01-01'),
        'end_date'      => Carbon::parse('2026-02-10'),
        'coverage'      => [
            'parts'      => true,
            'labor'      => false,
            'roadside'   => true,
            'limits'     => ['parts' => 5000],
            'deductible' => ['parts' => 250],
        ],
        'terms'         => ['transferable' => true],
    ]);

    $warranty->setRelation('subject', (object) ['display_name' => 'Truck 12']);
    $warranty->setRelation('vendor', (object) ['name' => 'Vendor One']);

    expect($warranty->subject_name)->toBe('Truck 12')
        ->and($warranty->vendor_name)->toBe('Vendor One')
        ->and($warranty->is_active)->toBeTrue()
        ->and($warranty->is_expired)->toBeFalse()
        ->and($warranty->days_remaining)->toBe(25)
        ->and($warranty->coverage_summary)->toMatchArray(['parts' => true, 'labor' => false, 'roadside' => true])
        ->and($warranty->status)->toBe('expiring_soon')
        ->and($warranty->hasCoverage('parts'))->toBeTrue()
        ->and($warranty->hasCoverage('labor'))->toBeFalse()
        ->and($warranty->getCoverageLimit('parts'))->toBe(5000)
        ->and($warranty->coversAmount('parts', 4000))->toBeTrue()
        ->and($warranty->coversAmount('parts', 6000))->toBeFalse()
        ->and($warranty->coversAmount('labor', 100))->toBeFalse()
        ->and($warranty->getDeductible('parts'))->toBe(250.0)
        ->and($warranty->isTransferable())->toBeTrue();

    $newSubject = new Asset();
    $newSubject->forceFill(['uuid' => 'new-asset-uuid']);
    expect($warranty->transferTo($newSubject))->toBeFalse()
        ->and($warranty->updates[0])->toMatchArray([
            'subject_type' => Asset::class,
            'subject_uuid' => 'new-asset-uuid',
        ]);

    $expired = new FleetOpsUpdatingWarrantyFake([
        'start_date' => Carbon::parse('2025-01-01'),
        'end_date'   => Carbon::parse('2025-12-31'),
    ]);
    expect($expired->is_active)->toBeFalse()
        ->and($expired->is_expired)->toBeTrue()
        ->and($expired->days_remaining)->toBe(0)
        ->and($expired->status)->toBe('expired');

    $future = new FleetOpsUpdatingWarrantyFake([
        'start_date' => Carbon::parse('2026-02-01'),
    ]);
    expect($future->is_active)->toBeFalse()
        ->and($future->status)->toBe('not_started');

    $lifetime = new FleetOpsUpdatingWarrantyFake([
        'start_date' => Carbon::parse('2026-01-01'),
        'coverage'   => ['deductible' => 100],
        'terms'      => [],
    ]);
    expect($lifetime->days_remaining)->toBeNull()
        ->and($lifetime->status)->toBe('active')
        ->and($lifetime->getDeductible('parts'))->toBe(100.0)
        ->and($lifetime->isTransferable())->toBeFalse();

    Carbon::setTestNow();
});

test('service area spatial factories and tracking number light accessors are stable', function () {
    $point = new Point(1.30, 103.80);

    expect(ServiceArea::createPolygonFromPoint($point, 100))->toBeInstanceOf(Fleetbase\LaravelMysqlSpatial\Types\Polygon::class)
        ->and(ServiceArea::createMultiPolygonFromPoint($point, 100))->toBeInstanceOf(MultiPolygon::class);

    $serviceArea = new ServiceArea(['status' => null, 'type' => null]);
    expect($serviceArea->status)->toBeNull()
        ->and($serviceArea->type)->toBeNull()
        ->and($serviceArea->toGeosCoordinates())->toBe([])
        ->and($serviceArea->toGeosLineStrings())->toBe([])
        ->and($serviceArea->toGeosPolygon())->toBeNull()
        ->and($serviceArea->asPolygon())->toBeNull();

    $tracking = new class extends TrackingNumber {
        public array $loaded = [];

        public function load($relations)
        {
            $this->loaded[] = $relations;

            return $this;
        }
    };
    $tracking->forceFill(['owner_type' => Asset::class]);
    $tracking->setRelation('status', (object) [
        'status'     => 'Order Created',
        'code'       => 'CREATED',
        'created_at' => Carbon::parse('2026-01-01 12:00:00'),
        'complete'   => false,
    ]);

    expect($tracking->last_status)->toBe('Order Created')
        ->and($tracking->last_status_code)->toBe('CREATED')
        ->and($tracking->last_status_updated_at->toDateTimeString())->toBe('2026-01-01 12:00:00')
        ->and($tracking->last_status_complete)->toBeFalse()
        ->and($tracking->type)->toBe('asset');
});
