<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\FleetOps\Models\auth')) {
    eval('namespace Fleetbase\FleetOps\Models; function auth() { return new class { public function id() { return "test-user"; } }; }');
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
