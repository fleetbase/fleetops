<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\ServiceRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsUpdatingMaintenanceFake extends Maintenance
{
    public array $updates        = [];
    public array $dateAttributes = [];

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->dateAttributes)) {
            return $this->dateAttributes[$key] ? Carbon::parse($this->dateAttributes[$key]) : null;
        }

        return parent::getAttribute($key);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return false;
    }
}

class FleetOpsUpdatingDeviceFake extends Device
{
    public array $updates            = [];
    public ?Carbon $lastOnlineAtFake = null;

    public function getAttribute($key)
    {
        if ($key === 'last_online_at') {
            return $this->lastOnlineAtFake;
        }

        return parent::getAttribute($key);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

test('contact accessors imports notifications and customer identity helpers are stable', function () {
    $contact = new Contact([
        'name'  => 'Jane Contact',
        'email' => 'jane@example.com',
        'phone' => '+1 (555) 111-2222',
        'type'  => 'customer',
    ]);

    $contact->setRelation('devices', new Collection([
        (object) ['platform' => 'android', 'token' => 'android-token'],
        (object) ['platform' => 'ios', 'token' => 'ios-token'],
        (object) ['platform' => 'web', 'token' => 'web-token'],
    ]));
    $contact->setRelation('photo', (object) ['url' => 'https://cdn.example/avatar.png']);

    expect($contact->isCustomer())->toBeTrue()
        ->and($contact->is_customer)->toBeTrue()
        ->and($contact->routeNotificationForFcm())->toBe(['android-token'])
        ->and($contact->routeNotificationForApn())->toBe([1 => 'ios-token'])
        ->and($contact->routeNotificationForTwilio())->toContain('555')
        ->and($contact->photo_url)->toBe('https://cdn.example/avatar.png');

    $imported = Contact::createFromImport([
        'full_name'     => 'Imported Person',
        'mobile_number' => '+1 555 333 4444',
        'email_address' => 'imported@example.com',
    ]);

    expect($imported)->toBeInstanceOf(Contact::class)
        ->and($imported->name)->toBe('Imported Person')
        ->and($imported->email)->toBe('imported@example.com')
        ->and($imported->type)->toBe('contact');

    $staffUser        = new Fleetbase\Models\User(['email' => 'staff@example.com', 'type' => 'admin']);
    $staffUser->phone = null;

    expect(fn () => $contact->assertCustomerUserCanBeAssigned($staffUser))
        ->toThrow(CustomerUserConflictException::class, 'existing staff user')
        ->and(Contact::customerUserConflictMessage($staffUser))
        ->toContain('email');
});

test('device accessors connection state configuration and command guards are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $device = new FleetOpsUpdatingDeviceFake([
        'options' => [
            'supported_features' => ['lock', 'reboot'],
            'sample_rate'        => 30,
        ],
    ]);

    $device->setRelation('photo', (object) ['url' => 'https://cdn.example/device.png']);
    $device->setRelation('warranty', (object) ['name' => 'Extended warranty']);
    $device->setRelation('telematic', null);
    $device->setRelation('attachable', (object) ['display_name' => 'Truck 99']);

    expect($device->photo_url)->toBe('https://cdn.example/device.png')
        ->and($device->warranty_name)->toBe('Extended warranty')
        ->and($device->telematic_name)->toBeNull()
        ->and($device->attached_to_name)->toBe('Truck 99')
        ->and($device->is_online)->toBeFalse()
        ->and($device->connection_status)->toBe('never_connected')
        ->and($device->supportsFeature('lock'))->toBeTrue()
        ->and($device->supportsFeature('unlock'))->toBeFalse()
        ->and($device->getConfiguration())->toMatchArray(['sample_rate' => 30])
        ->and($device->sendCommand('reboot'))->toBeFalse();

    $device->lastOnlineAtFake = Carbon::parse('2026-01-01 11:55:00');
    expect($device->is_online)->toBeTrue()
        ->and($device->connection_status)->toBe('online');

    $device->lastOnlineAtFake = Carbon::parse('2026-01-01 11:30:00');
    expect($device->connection_status)->toBe('recently_offline');

    $device->lastOnlineAtFake = Carbon::parse('2026-01-01 01:00:00');
    expect($device->connection_status)->toBe('offline');

    $device->lastOnlineAtFake = Carbon::parse('2025-12-30 11:00:00');
    expect($device->connection_status)->toBe('long_offline')
        ->and($device->updateConfiguration(['sample_rate' => 60, 'mode' => 'eco']))->toBeTrue()
        ->and($device->updates)->toHaveCount(1)
        ->and($device->updates[0]['options'])->toMatchArray(['sample_rate' => 60, 'mode' => 'eco']);

    Carbon::setTestNow();
});

test('maintenance accessors lifecycle guards and import mapping are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-10 12:00:00'));

    $maintenance = new FleetOpsUpdatingMaintenanceFake([
        'status'       => 'scheduled',
        'labor_cost'   => 1000,
        'parts_cost'   => 2500,
        'tax'          => 300,
        'total_cost'   => 3800,
        'line_items'   => [['label' => 'Oil']],
        'attachments'  => [],
        'meta'         => ['estimated_duration_hours' => 4],
    ]);
    $maintenance->dateAttributes = [
        'scheduled_at' => '2026-01-09 09:00:00',
        'started_at'   => '2026-01-09 08:00:00',
        'completed_at' => '2026-01-09 10:00:00',
    ];

    $maintenance->setRelation('maintainable', (object) ['display_name' => 'Truck 12']);
    $maintenance->setRelation('performedBy', (object) ['name' => 'Mechanic One']);
    $maintenance->setRelation('workOrder', (object) ['subject' => 'Quarterly service']);

    expect($maintenance->maintainable_name)->toBe('Truck 12')
        ->and($maintenance->performed_by_name)->toBe('Mechanic One')
        ->and($maintenance->work_order_subject)->toBe('Quarterly service')
        ->and($maintenance->duration_hours)->toBe(2.0)
        ->and($maintenance->is_overdue)->toBeTrue()
        ->and($maintenance->days_until_due)->toBeLessThan(0)
        ->and($maintenance->cost_breakdown)->toMatchArray(['subtotal' => 3500, 'total_cost' => 3800])
        ->and($maintenance->getEfficiencyRating())->toBe(100.0)
        ->and($maintenance->wasCompletedOnTime())->toBeFalse()
        ->and($maintenance->getCostPerHour())->toBe(1900.0);

    expect($maintenance->start())->toBeFalse()
        ->and($maintenance->complete(['labor_cost' => 1200, 'parts_cost' => 500, 'tax' => 100, 'notes' => 'Done']))->toBeFalse()
        ->and($maintenance->cancel('Duplicate ticket'))->toBeFalse()
        ->and($maintenance->addLineItem(['label' => 'Filter']))->toBeFalse()
        ->and($maintenance->removeLineItem(10))->toBeFalse()
        ->and($maintenance->addAttachment('file_uuid', 'Invoice'))->toBeFalse()
        ->and($maintenance->updates)->not->toBeEmpty();

    $completed                 = new FleetOpsUpdatingMaintenanceFake(['status' => 'completed']);
    $completed->dateAttributes = ['scheduled_at' => '2026-01-09'];
    expect($completed->is_overdue)->toBeFalse()
        ->and($completed->days_until_due)->toBeNull()
        ->and($completed->cancel())->toBeFalse();

    $imported = Maintenance::createFromImport([
        'maintenance_type'  => 'corrective',
        'priority'          => 'high',
        'description'       => 'Replace pads',
        'odometer_reading'  => '12345',
        'engine_hours'      => '88',
        'labor_cost'        => 1000,
        'parts_cost'        => 2000,
        'tax'               => 100,
        'total_cost'        => 3100,
        'currency'          => 'sgd',
    ]);

    expect($imported)->toBeInstanceOf(Maintenance::class)
        ->and($imported->type)->toBe('corrective')
        ->and($imported->priority)->toBe('high')
        ->and($imported->summary)->toBe('Replace pads')
        ->and($imported->currency)->toBe('SGD');

    Carbon::setTestNow();
});

test('service rate accessors flags fee normalization and quote helpers are stable', function () {
    $rate = new ServiceRate([
        'service_name'                  => 'Express',
        'rate_calculation_method'       => 'fixed_meter',
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'flat',
        'peak_hours_start'              => '00:00',
        'peak_hours_end'                => '23:59',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'percentage',
        'base_fee'                      => 500,
        'currency'                      => 'USD',
    ]);

    $rate->setRelation('serviceArea', (object) ['name' => 'Central']);
    $rate->setRelation('zone', (object) ['name' => 'Zone A']);

    expect($rate->service_area_name)->toBe('Central')
        ->and($rate->zone_name)->toBe('Zone A')
        ->and($rate->isRateCalculationMethod('fixed_meter'))->toBeTrue()
        ->and($rate->isRateCalculationMethod(['per_meter', 'fixed_meter']))->toBeTrue()
        ->and($rate->isFixedMeter())->toBeTrue()
        ->and($rate->isFixedRate())->toBeTrue()
        ->and($rate->isPerMeter())->toBeFalse()
        ->and($rate->isMultiZoneDistance())->toBeFalse()
        ->and($rate->isPerDrop())->toBeFalse()
        ->and($rate->isAlgorithm())->toBeFalse()
        ->and($rate->isParcelService())->toBeFalse()
        ->and($rate->hasPeakHoursFee())->toBeTrue()
        ->and($rate->isWithinPeakHours())->toBeTrue()
        ->and($rate->hasPeakHoursFlatFee())->toBeTrue()
        ->and($rate->hasPeakHoursPercentageFee())->toBeFalse()
        ->and($rate->hasCodFee())->toBeTrue()
        ->and($rate->hasCodFlatFee())->toBeFalse()
        ->and($rate->hasCodPercentageFee())->toBeTrue();

    $rate->setEstimatedDaysAttribute(null);
    expect($rate->estimated_days)->toBe(0);

    expect($rate->normalizeServiceRateFeePayload([
        'uuid'          => 'fee_uuid',
        'service_area'  => ['uuid' => 'area_uuid'],
        'zone'          => ['uuid' => 'zone_uuid'],
        'is_fallback'   => true,
        'label'         => 'Fallback',
        'ignored_field' => 'ignored',
    ]))->toMatchArray([
        'uuid'              => 'fee_uuid',
        'service_area_uuid' => null,
        'zone_uuid'         => null,
        'label'             => 'Fallback',
        'is_fallback'       => true,
    ]);

    expect($rate->normalizeServiceRateFeePayload('bad payload'))->toBeNull();
});
