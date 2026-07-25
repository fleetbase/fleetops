<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\ServiceRateFee;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
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

class FleetOpsSavingOrderFake extends Order
{
    public bool $saved = false;

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsLoadedPayloadFake extends Payload
{
    public ?string $uuidFake = null;

    public function getAttribute($key)
    {
        if ($key === 'uuid' && $this->uuidFake !== null) {
            return $this->uuidFake;
        }

        return parent::getAttribute($key);
    }

    public function load($relations)
    {
        return $this;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsPlainPlaceFake extends Place
{
    public function getAddressAttribute()
    {
        return $this->attributes['address'] ?? $this->attributes['name'] ?? $this->attributes['street1'] ?? null;
    }

    public function getAddressHtmlAttribute()
    {
        return $this->getAddressAttribute();
    }

    public function getCountryDataAttribute(): array
    {
        return [];
    }
}

class FleetOpsLoadedServiceRateFake extends ServiceRate
{
    public function load($relations)
    {
        return $this;
    }

    public function loadMissing($relations)
    {
        return $this;
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

test('waypoint model mirrors tracking number status accessors', function () {
    $waypoint = new Waypoint();
    $waypoint->setRelation('trackingNumber', (object) [
        'tracking_number'      => 'TRK-123',
        'last_status'          => 'Out for delivery',
        'last_status_code'     => 'out_for_delivery',
        'last_status_complete' => false,
    ]);

    expect($waypoint->getTrackingAttribute())->toBe('TRK-123')
        ->and($waypoint->getStatusAttribute())->toBe('Out for delivery')
        ->and($waypoint->getStatusCodeAttribute())->toBe('out_for_delivery')
        ->and($waypoint->getCompleteAttribute())->toBeFalse();
});

test('fuel report accessors mutators and meta helpers are stable', function () {
    $report = new FuelReport([
        'amount' => '$45.67',
        'meta'   => [
            'source'                         => 'provider',
            'provider'                       => 'fuelx',
            'fuel_provider_transaction_uuid' => 'transaction_uuid',
        ],
    ]);

    $report->setRelation('driver', (object) ['name' => 'Driver One']);
    $report->setRelation('vehicle', (object) ['display_name' => 'Truck 8']);
    $report->setRelation('reportedBy', (object) ['name' => 'Dispatcher One']);

    expect($report->amount)->toBe(4567)
        ->and($report->driver_name)->toBe('Driver One')
        ->and($report->vehicle_name)->toBe('Truck 8')
        ->and($report->reporter_name)->toBe('Dispatcher One')
        ->and($report->source)->toBe('provider')
        ->and($report->provider)->toBe('fuelx')
        ->and($report->fuel_provider_transaction_uuid)->toBe('transaction_uuid');
});

test('vendor accessors mutators options notifications and import mapping are stable', function () {
    $vendor = new Vendor([
        'name'   => 'Vendor One',
        'phone'  => '+1 (555) 444-3333',
        'type'   => null,
        'status' => null,
    ]);

    $vendor->setRelation('logo', (object) ['url' => 'https://cdn.example/vendor.png']);
    $vendor->setRelation('place', (object) [
        'address_html' => '1 Vendor Way',
        'street1'      => '1 Vendor Way',
    ]);

    expect($vendor->type)->toBe('vendor')
        ->and($vendor->status)->toBe('active')
        ->and($vendor->logo_url)->toBe('https://cdn.example/vendor.png')
        ->and($vendor->address)->toBe('1 Vendor Way')
        ->and($vendor->address_street)->toBe('1 Vendor Way')
        ->and($vendor->routeNotificationForTwilio())->toContain('555');

    $vendor->type   = null;
    $vendor->status = null;

    expect($vendor->type)->toBe('vendor')
        ->and($vendor->status)->toBe('active');

    $slugOptions = $vendor->getSlugOptions();
    $logOptions  = $vendor->getActivitylogOptions();

    expect($slugOptions->generateSlugFrom)->toBe(['name'])
        ->and($slugOptions->slugField)->toBe('slug')
        ->and($logOptions->logAttributes)->toContain('name', 'email', 'company_uuid')
        ->and($logOptions->logOnlyDirty)->toBeTrue();

    $imported = Vendor::createFromImport([
        'full_name'     => 'Imported Vendor',
        'mobile_number' => '+1 555 222 1111',
        'email_address' => 'vendor@example.com',
        'website_url'   => 'https://vendor.example',
        'country_name'  => 'United States',
    ]);

    expect($imported)->toBeInstanceOf(Vendor::class)
        ->and($imported->name)->toBe('Imported Vendor')
        ->and($imported->phone)->toContain('555')
        ->and($imported->email)->toBe('vendor@example.com')
        ->and($imported->type)->toBe('vendor')
        ->and($imported->status)->toBe('active')
        ->and($imported->country)->toBe('US');
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

test('order accessors mutators and payload association helpers are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 08:00:00'));

    $payload = new FleetOpsLoadedPayloadFake([
        'uuid'      => 'payload_uuid',
    ]);
    $payload->setAttribute('public_id', 'payload_public_id');
    $payload->setRelation('pickup', new FleetOpsPlainPlaceFake(['name' => 'Pickup dock']));
    $payload->setRelation('dropoff', new FleetOpsPlainPlaceFake(['street1' => 'Dropoff street']));
    $payload->setRelation('return', new FleetOpsPlainPlaceFake(['street1' => 'Return counter']));

    $order = new FleetOpsSavingOrderFake([
        'driver_assigned_uuid'  => 'driver_uuid',
        'customer_type'         => Contact::class,
        'facilitator_type'      => Vendor::class,
        'scheduled_at'          => '2026-02-04 09:30:00',
        'dispatched_at'         => null,
        'adhoc'                 => false,
        'orchestrator_priority' => '7',
        'type'                  => 'Express Delivery',
        'status'                => 'Driver Assigned',
    ]);

    $order->setRelation('driverAssigned', (object) ['name' => 'Driver One']);
    $order->setRelation('vehicleAssigned', (object) ['display_name' => 'Van 4']);
    $order->setRelation('trackingNumber', (object) [
        'tracking_number' => 'TN123',
        'qr_code'         => 'qr-data',
    ]);
    $order->setRelation('transaction', (object) ['amount' => 1200, 'currency' => 'USD']);
    $order->setRelation('customer', (object) ['name' => 'Customer One', 'phone' => '+1555000']);
    $order->setRelation('facilitator', (object) ['name' => 'Vendor One']);
    $order->setRelation('payload', $payload);
    $order->setRelation('purchaseRate', (object) ['public_id' => 'purchase_rate_id']);
    $order->setRelation('createdBy', (object) ['name' => 'Creator']);
    $order->setRelation('updatedBy', (object) ['name' => 'Updater']);

    $order->time_window_start = '09:00:00';
    $order->time_window_end   = '2026-02-05 17:00:00';

    expect($order->driver_name)->toBe('Driver One')
        ->and($order->vehicle_name)->toBe('Van 4')
        ->and($order->tracking)->toBe('TN123')
        ->and($order->transaction_amount)->toBe(1200)
        ->and($order->transaction_currency)->toBe('USD')
        ->and($order->customer_name)->toBe('Customer One')
        ->and($order->customer_phone)->toBe('+1555000')
        ->and($order->facilitator_name)->toBe('Vendor One')
        ->and($order->customer_is_contact)->toBeTrue()
        ->and($order->customer_is_vendor)->toBeFalse()
        ->and($order->facilitator_is_vendor)->toBeTrue()
        ->and($order->facilitator_is_contact)->toBeFalse()
        ->and($order->pickup_name)->toBe('Pickup dock')
        ->and($order->dropoff_name)->toBe('Dropoff street')
        ->and($order->return_name)->toBe('Return counter')
        ->and($order->payload_id)->toBe('payload_public_id')
        ->and($order->purchase_rate_id)->toBe('purchase_rate_id')
        ->and($order->qr_code)->toBe('qr-data')
        ->and($order->created_by_name)->toBe('Creator')
        ->and($order->updated_by_name)->toBe('Updater')
        ->and($order->has_driver_assigned)->toBeTrue()
        ->and($order->is_scheduled)->toBeTrue()
        ->and($order->is_assigned_not_dispatched)->toBeTrue()
        ->and($order->is_not_dispatched)->toBeTrue()
        ->and($order->orchestrator_priority)->toBe(7)
        ->and($order->type)->toBe('express-delivery')
        ->and($order->status)->toBe('driver_assigned')
        ->and($order->time_window_start->toDateTimeString())->toBe('2026-02-03 09:00:00')
        ->and($order->time_window_end->toDateTimeString())->toBe('2026-02-05 17:00:00');

    $order->orchestrator_priority = 'not numeric';
    $order->type                  = null;
    $order->status                = null;

    expect($order->orchestrator_priority)->toBe(50)
        ->and($order->type)->toBe('default')
        ->and($order->status)->toBe('created');

    $newPayload           = new FleetOpsLoadedPayloadFake();
    $newPayload->uuidFake = 'new_payload_uuid';
    expect($order->setPayload($newPayload))->toBe($order)
        ->and($order->payload_uuid)->toBe('new_payload_uuid')
        ->and($order->payload)->toBe($newPayload)
        ->and($order->saved)->toBeTrue();

    Carbon::setTestNow();
});

test('payload and place pure accessors normalize fallback data', function () {
    $pickup  = new FleetOpsPlainPlaceFake(['name' => 'Pickup name', 'country' => 'SG']);
    $dropoff = new FleetOpsPlainPlaceFake(['street1' => 'Dropoff street', 'country' => 'MY']);
    $return  = new FleetOpsPlainPlaceFake(['street1' => 'Return address']);

    $payload = new FleetOpsLoadedPayloadFake(['cod_amount' => '$12.34']);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', $return);
    $payload->setRelation('waypoints', collect([
        new Place(['name' => 'Waypoint one']),
        ['name' => 'Waypoint array'],
        (object) ['name' => 'Ignored object'],
    ]));

    expect($payload->cod_amount)->toBe(1234)
        ->and($payload->pickup_name)->toBe('Pickup name')
        ->and($payload->dropoff_name)->toBe('Dropoff street')
        ->and($payload->return_name)->toBe('Return address')
        ->and($payload->getPickupRegion())->toBe('SG')
        ->and($payload->getCountryCode())->toBe('SG')
        ->and($payload->getAllStops())->toHaveCount(4)
        ->and($payload->getPickupLocation())->toBeInstanceOf(Point::class);

    $place = new Place([
        'street1'     => '  1 Main Street  ',
        'street2'     => '',
        'city'        => '  Singapore ',
        'country'     => ' SG ',
        'postal_code' => null,
    ]);

    expect(Place::composeGeocodingQuery($place->getAttributes()))->toBe('1 Main Street, Singapore, SG')
        ->and(Place::normalizePlaceValue(['bad']))->toBeNull()
        ->and(Place::normalizePlaceValue('  ok  '))->toBe('ok')
        ->and(Place::mergeStructuredPlaceAttributes([
            'street1' => '  Explicit  ',
            'street2' => '',
            'city'    => '  City  ',
            'phone'   => '  +1555 ',
        ], [
            'street1' => 'Geocoded',
            'country' => 'US',
        ]))->toMatchArray([
            'street1' => 'Explicit',
            'city'    => 'City',
            'country' => 'US',
            'phone'   => '+1555',
        ]);
});

test('service rate quote math and fee selection helpers are stable', function () {
    $rate = new FleetOpsLoadedServiceRateFake([
        'uuid'                          => 'rate_uuid',
        'base_fee'                      => 500,
        'currency'                      => 'USD',
        'rate_calculation_method'       => 'per_meter',
        'per_meter_flat_rate_fee'       => 2,
        'per_meter_unit'                => 'km',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 125,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'percentage',
        'peak_hours_percent'            => 10,
        'peak_hours_start'              => '00:00',
        'peak_hours_end'                => '23:59',
    ]);

    $shortFee = new ServiceRateFee(['distance' => 5, 'min' => 1, 'max' => 2, 'fee' => 100]);
    $shortFee->setAttribute('uuid', 'short');
    $longFee = new ServiceRateFee(['distance' => 10, 'min' => 3, 'max' => 6, 'fee' => 200]);
    $longFee->setAttribute('uuid', 'long');
    $rate->setRelation('rateFees', collect([$shortFee, $longFee]));

    [$total, $lines] = $rate->quoteFromPreliminaryData(
        [(object) ['type' => 'parcel', 'weight' => 2, 'weight_unit' => 'lb']],
        [new Place(['name' => 'A']), new Place(['name' => 'B']), new Place(['name' => 'C'])],
        2500,
        600,
        true
    );

    $reflection         = new ReflectionClass(ServiceRate::class);
    $distanceNormalizer = $reflection->getMethod('normalizeDistanceForUnit');
    $moneyNormalizer    = $reflection->getMethod('normalizeCalculatedMoney');
    $variableBuilder    = $reflection->getMethod('buildAlgorithmVariables');
    $endpointInferrer   = $reflection->getMethod('inferEndpointCountFromStops');
    $weightNormalizer   = $reflection->getMethod('normalizeEntityWeightToKilograms');
    $haversine          = $reflection->getMethod('haversineDistanceInMeters');
    $interpolator       = $reflection->getMethod('interpolateLngLat');

    expect($total)->toBe(681)
        ->and($lines)->toHaveCount(4)
        ->and($rate->findServiceRateFeeByDistance(7500)->uuid)->toBe('long')
        ->and($rate->findServiceRateFeeByDistance(12000)->uuid)->toBe('long')
        ->and($rate->findServiceRateFeeByMinMax(4)->uuid)->toBe('long')
        ->and($rate->findServiceRateFeeByMinMax(99)->uuid)->toBe('long')
        ->and($distanceNormalizer->invoke($rate, 1000, 'km'))->toBe(1.0)
        ->and(round($distanceNormalizer->invoke($rate, 1609.344, 'mi'), 3))->toBe(1.0)
        ->and($moneyNormalizer->invoke($rate, 10.6))->toBe(11)
        ->and($endpointInferrer->invoke($rate, [1, 2, 3]))->toBe(2)
        ->and($endpointInferrer->invoke($rate, [1]))->toBe(1)
        ->and(round($weightNormalizer->invoke($rate, ['weight' => 1000, 'weight_unit' => 'g']), 2))->toBe(1.0)
        ->and(round($weightNormalizer->invoke($rate, ['weight' => 16, 'weight_unit' => 'oz']), 4))->toBe(0.4536)
        ->and($interpolator->invoke($rate, ['lat' => 0, 'lng' => 0], ['lat' => 10, 'lng' => 20], 0.25))->toBe(['lat' => 2.5, 'lng' => 5.0])
        ->and((int) round($haversine->invoke($rate, 0, 0, 0, 1)))->toBe(111195);

    $variables = $variableBuilder->invoke($rate, [
        ['type' => 'parcel', 'weight' => 1000, 'weight_unit' => 'g'],
        ['type' => 'item', 'weight' => 2, 'weight_unit' => 'kg'],
    ], [new Place(), new Place(), new Place()], 1200, 300, 2);

    expect($variables)->toMatchArray([
        'distance_m' => 1200,
        'time_s'     => 300,
        'stops'      => 3,
        'waypoints'  => 1,
        'parcels'    => 1,
        'entities'   => 2,
        'base_fee'   => 500,
        'weight_kg'  => 3.0,
    ]);
});
