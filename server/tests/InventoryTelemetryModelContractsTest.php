<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\FleetOps\Models\auth')) {
    eval('namespace Fleetbase\FleetOps\Models; function auth() { return new class { public function id() { return "test-user"; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return new class { public function performedOn($subject) { return $this; } public function withProperties(array $properties) { return $this; } public function log(string $message) { return true; } }; }');
}

use Fleetbase\FleetOps\Models\Asset;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Sensor;
use Illuminate\Support\Carbon;

class FleetOpsUpdatingDeviceEventFake extends DeviceEvent
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

class FleetOpsUpdatingSensorFake extends Sensor
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

function fleetopsInvokeHidden(object $object, string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $arguments);
}

function fleetopsOrderConfigFlow(): array
{
    return [
        'activities' => [
            [
                'key'        => 'order_created',
                'code'       => 'created',
                'status'     => 'Created',
                'activities' => ['started'],
            ],
            [
                'key'        => 'order_started',
                'code'       => 'started',
                'status'     => 'Started',
                'activities' => ['completed'],
            ],
            [
                'key'      => 'order_completed',
                'code'     => 'completed',
                'status'   => 'Completed',
                'complete' => true,
            ],
        ],
        'created' => [
            'key'        => 'order_created',
            'code'       => 'created',
            'status'     => 'Created',
            'activities' => ['started'],
        ],
        'started' => [
            'key'        => 'order_started',
            'code'       => 'started',
            'status'     => 'Started',
            'activities' => ['completed'],
        ],
        'completed' => [
            'key'      => 'order_completed',
            'code'     => 'completed',
            'status'   => 'Completed',
            'complete' => true,
        ],
    ];
}

test('device event accessors data helpers and alert decisions are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $event = new FleetOpsUpdatingDeviceEventFake([
        'uuid'         => 'event-uuid',
        'event_type'   => 'warning',
        'severity'     => 'medium',
        'message'      => 'Battery low',
        'ident'        => 'provider-device-9',
        'occurred_at'  => Carbon::parse('2026-01-01 11:45:00'),
        'processed_at' => Carbon::parse('2026-01-01 11:50:00'),
        'data'         => [
            'battery' => [
                'level' => 12,
            ],
        ],
    ]);

    $event->setRelation('device', (object) [
        'name'              => 'Tracker 9',
        'device_id'         => null,
        'imei'              => '359881234567890',
        'serial_number'     => 'SN-9',
        'connection_status' => 'online',
        'status'            => 'active',
        'photo_url'         => 'https://cdn.example/device.png',
        'telematic_uuid'    => 'telematic-uuid',
        'telematic'         => (object) [
            'name'                => 'Provider One',
            'provider_descriptor' => ['key' => 'provider-one'],
        ],
    ]);

    expect($event->device_name)->toBe('Tracker 9')
        ->and($event->device_id)->toBe('provider-device-9')
        ->and($event->device_imei)->toBe('359881234567890')
        ->and($event->device_serial_number)->toBe('SN-9')
        ->and($event->device_connection_status)->toBe('online')
        ->and($event->device_status)->toBe('active')
        ->and($event->device_photo_url)->toBe('https://cdn.example/device.png')
        ->and($event->telematic_uuid)->toBe('telematic-uuid')
        ->and($event->telematic_name)->toBe('Provider One')
        ->and($event->provider_descriptor)->toBe(['key' => 'provider-one'])
        ->and($event->is_processed)->toBeTrue()
        ->and($event->age_minutes)->toBe(15)
        ->and($event->processing_delay_minutes)->toBe(5)
        ->and($event->getData('battery.level'))->toBe(12)
        ->and($event->getData('battery.voltage', 'missing'))->toBe('missing')
        ->and($event->shouldTriggerAlert())->toBeTrue()
        ->and(fleetopsInvokeHidden($event, 'generateAlertMessage'))->toBe("Device 'Tracker 9' issued a warning: Battery low");

    $unprocessed = new FleetOpsUpdatingDeviceEventFake([
        'event_type'   => 'telemetry',
        'severity'     => 'low',
        'created_at'   => Carbon::parse('2026-01-01 11:00:00'),
        'processed_at' => null,
    ]);

    expect($unprocessed->is_processed)->toBeFalse()
        ->and($unprocessed->processing_delay_minutes)->toBeNull()
        ->and($unprocessed->shouldTriggerAlert())->toBeFalse()
        ->and($unprocessed->setData('engine.temperature', 185))->toBeTrue()
        ->and($unprocessed->updates[0]['data'])->toMatchArray(['engine' => ['temperature' => 185]]);

    Carbon::setTestNow();
});

test('sensor accessors thresholds calibration and history are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $sensor = new FleetOpsUpdatingSensorFake([
        'name'                 => 'Cargo temperature',
        'sensor_type'          => 'temperature',
        'unit'                 => 'C',
        'status'               => 'active',
        'last_value'           => 24,
        'min_threshold'        => 2,
        'max_threshold'        => 8,
        'threshold_inclusive'  => true,
        'last_reading_at'      => Carbon::parse('2026-01-01 11:59:00'),
        'report_frequency_sec' => 60,
        'calibration'          => ['offset' => 1.5, 'scale' => 2],
    ]);
    $sensor->forceFill(['uuid' => 'sensor-uuid']);

    $sensor->setRelation('photo', (object) ['url' => 'https://cdn.example/sensor.png']);
    $sensor->setRelation('device', (object) ['name' => 'Tracker 9']);
    $sensor->setRelation('warranty', (object) ['name' => 'Sensor warranty']);
    $sensor->setRelation('sensorable', (object) ['display_name' => 'Trailer 12']);

    expect($sensor->photo_url)->toBe('https://cdn.example/sensor.png')
        ->and($sensor->device_name)->toBe('Tracker 9')
        ->and($sensor->warranty_name)->toBe('Sensor warranty')
        ->and($sensor->attached_to_name)->toBe('Trailer 12')
        ->and($sensor->is_active)->toBeTrue()
        ->and($sensor->threshold_status)->toBe('out_of_range')
        ->and($sensor->last_reading_formatted)->toBe('24 C')
        ->and($sensor->applyCalibratedValue(10))->toBe(21.5)
        ->and(fleetopsInvokeHidden($sensor, 'getSeverityForThresholdStatus', ['above_maximum']))->toBe('medium')
        ->and(fleetopsInvokeHidden($sensor, 'generateThresholdAlertMessage', [24, 'above_maximum']))
        ->toBe("Sensor 'Cargo temperature' reading (24 C) exceeds maximum threshold (8 C)");

    $sensor->forceFill(['last_value' => 8, 'threshold_inclusive' => false]);
    expect($sensor->threshold_status)->toBe('out_of_range');

    $sensor->forceFill(['last_value' => 8, 'threshold_inclusive' => true]);
    expect($sensor->threshold_status)->toBe('normal');

    expect($sensor->calibrate(0.5, 1.25))->toBeTrue()
        ->and($sensor->updates[0]['calibration'])->toMatchArray(['offset' => 0.5, 'scale' => 1.25]);

    $history = $sensor->getReadingHistory(25, 12);
    expect($history['sensor_uuid'])->toBe('sensor-uuid')
        ->and($history['period'])->toHaveKeys(['start', 'end'])
        ->and($history['summary'])->toMatchArray(['count' => 0, 'last' => 8]);

    $sensor->forceFill(['status' => 'offline']);
    expect($sensor->is_active)->toBeFalse();

    Carbon::setTestNow();
});

test('device event direct helper methods cover telemetry export and alert variants', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $event = new FleetOpsUpdatingDeviceEventFake([
        'uuid'         => 'event-uuid',
        'company_uuid' => 'company-uuid',
        'device_uuid'  => 'device-uuid',
        'event_type'   => 'critical_failure',
        'severity'     => 'critical',
        'message'      => 'Power loss',
        'ident'        => 'provider-ident',
        'occurred_at'  => Carbon::parse('2026-01-01 11:40:00'),
        'processed_at' => Carbon::parse('2026-01-01 11:55:00'),
        'created_at'   => Carbon::parse('2026-01-01 11:30:00'),
        'data'         => ['engine' => ['rpm' => 1800]],
    ]);
    $event->forceFill(['public_id' => 'device_event_public']);

    $event->setRelation('device', (object) [
        'name'              => 'Telemetry Box',
        'device_id'         => 'device-provider-id',
        'imei'              => 'imei-1',
        'serial_number'     => 'serial-1',
        'connection_status' => 'recently_offline',
        'status'            => 'maintenance',
        'photo_url'         => 'https://cdn.example/device-photo.png',
        'telematic_uuid'    => 'telematic-uuid',
        'telematic'         => (object) [
            'name'                => 'Telematic One',
            'provider_descriptor' => ['key' => 'provider-key'],
        ],
    ]);

    expect($event->getDeviceNameAttribute())->toBe('Telemetry Box')
        ->and($event->getDeviceIdAttribute())->toBe('device-provider-id')
        ->and($event->getDeviceImeiAttribute())->toBe('imei-1')
        ->and($event->getDeviceSerialNumberAttribute())->toBe('serial-1')
        ->and($event->getDeviceConnectionStatusAttribute())->toBe('recently_offline')
        ->and($event->getDeviceStatusAttribute())->toBe('maintenance')
        ->and($event->getDevicePhotoUrlAttribute())->toBe('https://cdn.example/device-photo.png')
        ->and($event->getTelematicUuidAttribute())->toBe('telematic-uuid')
        ->and($event->getTelematicNameAttribute())->toBe('Telematic One')
        ->and($event->getProviderDescriptorAttribute())->toBe(['key' => 'provider-key'])
        ->and($event->getIsProcessedAttribute())->toBeTrue()
        ->and($event->getAgeMinutesAttribute())->toBe(20)
        ->and($event->getProcessingDelayMinutesAttribute())->toBe(15)
        ->and($event->getSeverityLevel())->toBe(4)
        ->and($event->getData('engine.rpm'))->toBe(1800)
        ->and($event->getData('engine.load', 'unknown'))->toBe('unknown')
        ->and($event->shouldTriggerAlert())->toBeTrue()
        ->and($event->exportForAnalysis())->toMatchArray([
            'event_id'                 => 'device_event_public',
            'device_uuid'              => 'device-uuid',
            'device_name'              => 'Telemetry Box',
            'event_type'               => 'critical_failure',
            'severity'                 => 'critical',
            'message'                  => 'Power loss',
            'processing_delay_minutes' => 15,
            'data'                     => ['engine' => ['rpm' => 1800]],
        ]);

    $fallbackEvent = new FleetOpsUpdatingDeviceEventFake(['ident' => 'fallback-ident']);
    $fallbackEvent->setRelation('device', null);
    expect($fallbackEvent->getDeviceIdAttribute())->toBe('fallback-ident')
        ->and($fallbackEvent->createAlert())->toBeNull();

    expect(fleetopsInvokeHidden($event, 'generateAlertMessage'))->toBe("Critical failure detected on device 'Telemetry Box': Power loss");

    foreach ([
        ['error', "Device 'Telemetry Box' reported an error: Power loss"],
        ['security_breach', "Security breach detected on device 'Telemetry Box': Power loss"],
        ['maintenance_required', "Device 'Telemetry Box' requires maintenance: Power loss"],
        ['threshold_exceeded', "Threshold exceeded on device 'Telemetry Box': Power loss"],
        ['telemetry_update', "Device 'Telemetry Box' event (telemetry_update): Power loss"],
    ] as [$type, $message]) {
        $event->forceFill(['event_type' => $type]);
        expect(fleetopsInvokeHidden($event, 'generateAlertMessage'))->toBe($message);
    }

    foreach ([
        'high'     => 3,
        'medium'   => 2,
        'low'      => 1,
        'info'     => 0,
    ] as $severity => $level) {
        $event->forceFill(['severity' => $severity, 'event_type' => 'telemetry_update']);
        expect($event->getSeverityLevel())->toBe($level);
    }

    $unprocessed = new FleetOpsUpdatingDeviceEventFake([
        'occurred_at'  => Carbon::parse('2026-01-01 11:50:00'),
        'processed_at' => null,
    ]);

    expect($unprocessed->getProcessingDelayMinutesAttribute())->toBeNull()
        ->and($unprocessed->markAsProcessed())->toBeTrue()
        ->and($unprocessed->updates[0]['processed_at'])->toBeInstanceOf(Carbon::class)
        ->and((new FleetOpsUpdatingDeviceEventFake(['processed_at' => Carbon::parse('2026-01-01 11:59:00')]))->markAsProcessed())->toBeFalse();

    Carbon::setTestNow();
});

test('sensor direct helper methods cover threshold branches and formatted data', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $sensor = new FleetOpsUpdatingSensorFake([
        'name'                 => 'Fuel level',
        'sensor_type'          => 'fuel',
        'unit'                 => '%',
        'status'               => 'active',
        'last_value'           => 50,
        'min_threshold'        => 20,
        'max_threshold'        => 80,
        'threshold_inclusive'  => true,
        'last_reading_at'      => Carbon::parse('2026-01-01 11:58:00'),
        'report_frequency_sec' => 120,
        'calibration'          => ['offset' => -2, 'scale' => 1.5],
    ]);
    $sensor->forceFill(['uuid' => 'sensor-uuid']);

    $sensor->setRelation('device', (object) ['name' => 'Device One']);
    $sensor->setRelation('warranty', (object) ['name' => 'Warranty One']);
    $sensor->setRelation('sensorable', (object) ['name' => 'Trailer One']);
    $sensor->setRelation('photo', null);

    $history = $sensor->getReadingHistory(10, 6);

    expect($sensor->getPhotoUrlAttribute())->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
        ->and($sensor->getDeviceNameAttribute())->toBe('Device One')
        ->and($sensor->getWarrantyNameAttribute())->toBe('Warranty One')
        ->and($sensor->getAttachedToNameAttribute())->toBe('Trailer One')
        ->and($sensor->getIsActiveAttribute())->toBeTrue()
        ->and($sensor->getThresholdStatusAttribute())->toBe('normal')
        ->and($sensor->getLastReadingFormattedAttribute())->toBe('50 %')
        ->and($sensor->applyCalibratedValue(10))->toBe(13.0)
        ->and($history['sensor_uuid'])->toBe('sensor-uuid')
        ->and($history['sensor_name'])->toBe('Fuel level')
        ->and($history['readings'])->toBe([])
        ->and($history['summary'])->toMatchArray(['count' => 0, 'last' => 50]);

    $sensor->forceFill(['last_value' => 10, 'min_threshold' => 20, 'max_threshold' => null, 'threshold_inclusive' => true]);
    expect($sensor->getThresholdStatusAttribute())->toBe('below_minimum')
        ->and(fleetopsInvokeHidden($sensor, 'getSeverityForThresholdStatus', ['below_minimum']))->toBe('medium')
        ->and(fleetopsInvokeHidden($sensor, 'generateThresholdAlertMessage', [10, 'below_minimum']))
        ->toBe("Sensor 'Fuel level' reading (10 %) is below minimum threshold (20 %)");

    $sensor->forceFill(['last_value' => 90, 'min_threshold' => null, 'max_threshold' => 80, 'threshold_inclusive' => false]);
    expect($sensor->getThresholdStatusAttribute())->toBe('above_maximum')
        ->and(fleetopsInvokeHidden($sensor, 'generateThresholdAlertMessage', [90, 'above_maximum']))
        ->toBe("Sensor 'Fuel level' reading (90 %) exceeds maximum threshold (80 %)");

    $sensor->forceFill(['last_value' => null]);
    expect($sensor->getThresholdStatusAttribute())->toBe('normal')
        ->and($sensor->getLastReadingFormattedAttribute())->toBeNull()
        ->and(fleetopsInvokeHidden($sensor, 'getSeverityForThresholdStatus', ['unknown']))->toBe('low')
        ->and(fleetopsInvokeHidden($sensor, 'generateThresholdAlertMessage', [0, 'unknown']))
        ->toBe("Sensor 'Fuel level' threshold violation detected");

    $sensor->forceFill(['status' => 'active', 'last_reading_at' => Carbon::parse('2026-01-01 11:00:00')]);
    expect($sensor->getIsActiveAttribute())->toBeFalse();

    $sensor->forceFill(['status' => 'active', 'last_reading_at' => null, 'report_frequency_sec' => null]);
    expect($sensor->getIsActiveAttribute())->toBeTrue();

    Carbon::setTestNow();
});

test('part inventory helpers compatibility and import mapping are stable', function () {
    $part = new Part([
        'sku'              => 'FILTER-1',
        'name'             => 'Fuel Filter',
        'manufacturer'     => 'Fleet Parts',
        'model'            => 'FP-100',
        'quantity_on_hand' => 4,
        'unit_cost'        => 1250,
        'msrp'             => 1750,
        'currency'         => 'USD',
        'specs'            => [
            'low_stock_threshold' => 5,
            'reorder_point'       => 6,
            'reorder_quantity'    => 12,
            'compatible_assets'   => ['tractor', 'MakeOne ModelOne'],
        ],
    ]);

    $part->setRelation('vendor', (object) ['name' => 'Fleet Vendor']);
    $part->setRelation('warranty', (object) ['name' => 'Parts warranty']);
    $part->setRelation('photo', (object) ['url' => 'https://cdn.example/part.png']);
    $part->setRelation('asset', (object) ['name' => 'Truck asset']);

    expect($part->vendor_name)->toBe('Fleet Vendor')
        ->and($part->warranty_name)->toBe('Parts warranty')
        ->and($part->photo_url)->toBe('https://cdn.example/part.png')
        ->and($part->asset_name)->toBe('Truck asset')
        ->and($part->total_value)->toBe(5000.0)
        ->and($part->is_in_stock)->toBeTrue()
        ->and($part->is_low_stock)->toBeTrue()
        ->and($part->getReorderPoint())->toBe(6)
        ->and($part->getReorderQuantity())->toBe(12)
        ->and($part->needsReorder())->toBeTrue()
        ->and($part->getEstimatedCost(2))->toBe(2500.0)
        ->and($part->getEstimatedCost(2, true))->toBe(3500.0);

    $asset = new Asset(['type' => 'tractor', 'make' => 'Other', 'model' => 'Asset']);
    expect($part->isCompatibleWith($asset))->toBeTrue();

    $asset = new Asset(['type' => 'truck', 'make' => 'MakeOne', 'model' => 'ModelOne']);
    expect($part->isCompatibleWith($asset))->toBeTrue();

    $asset = new Asset(['type' => 'truck', 'make' => 'MakeTwo', 'model' => 'ModelTwo']);
    expect($part->isCompatibleWith($asset))->toBeFalse();

    $openPart  = new Part(['quantity_on_hand' => 0, 'specs' => []]);
    $openAsset = new Asset(['type' => 'anything']);
    expect($openPart->is_in_stock)->toBeFalse()
        ->and($openPart->is_low_stock)->toBeTrue()
        ->and($openPart->isCompatibleWith($openAsset))->toBeTrue()
        ->and($openPart->getReorderPoint())->toBe(5)
        ->and($openPart->getReorderQuantity())->toBe(10)
        ->and($openPart->getEstimatedCost(3))->toBe(0.0)
        ->and($openPart->addStock(0))->toBeFalse()
        ->and($openPart->removeStock(1))->toBeFalse()
        ->and($openPart->setStock(-1))->toBeFalse();

    $imported = Part::createFromImport([
        'part_number'  => 'BELT-2',
        'part_name'    => 'Drive Belt',
        'part_type'    => 'replacement',
        'make'         => 'Fleet Parts',
        'part_model'   => 'DB-200',
        'serial'       => 'SER-200',
        'qty'          => '7',
        'cost'         => 900,
        'retail_price' => 1200,
        'currency'     => 'usd',
    ]);

    expect($imported)->toBeInstanceOf(Part::class)
        ->and($imported->sku)->toBe('BELT-2')
        ->and($imported->name)->toBe('Drive Belt')
        ->and($imported->type)->toBe('replacement')
        ->and($imported->manufacturer)->toBe('Fleet Parts')
        ->and($imported->model)->toBe('DB-200')
        ->and($imported->serial_number)->toBe('SER-200')
        ->and($imported->quantity_on_hand)->toBe(7)
        ->and($imported->currency)->toBe('USD');
});

test('order config activities context and fallback states are stable', function () {
    $config = new OrderConfig([
        'flow' => fleetopsOrderConfigFlow(),
    ]);

    $order = new Order(['status' => 'created']);
    $config->setOrderContext($order);

    expect($config->type)->toBe('order-config')
        ->and($config->getOrderContext())->toBe($order)
        ->and($config->activities())->toHaveCount(4)
        ->and($config->getCreatedActivity()?->code)->toBe('created')
        ->and($config->getDispatchActivity())->toBeNull()
        ->and($config->currentActivity()?->code)->toBe('created')
        ->and($config->nextActivity())->toHaveCount(1)
        ->and($config->nextFirstActivity()?->code)->toBe('started')
        ->and($config->afterNextActivity()?->code)->toBe('completed')
        ->and($config->getActivityByCode('started')?->status)->toBe('Started')
        ->and($config->getCompletedActivity()->complete())->toBeTrue()
        ->and($config->getStartedActivity()->code)->toBe('started')
        ->and($config->getCanceledActivity()->code)->toBe('canceled');

    $order->status = 'started';
    expect($config->currentActivity()?->code)->toBe('started')
        ->and($config->nextFirstActivity()?->code)->toBe('completed');

    $fallbackConfig = new OrderConfig([
        'flow' => [
            'activities' => [],
        ],
    ]);

    expect($fallbackConfig->getCompletedActivity()->code)->toBe('completed')
        ->and($fallbackConfig->getStartedActivity()->code)->toBe('started')
        ->and(fn () => $fallbackConfig->getOrderContext())->toThrow(Exception::class, 'No order context');
});
