<?php

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Support\Carbon;

class FleetOpsTelematicServiceDeviceFake extends Device
{
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }
}

function fleetOpsTelematicServiceInvoke(TelematicService $service, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($service, $arguments);
}

function fleetOpsTelematicService(): TelematicService
{
    return new TelematicService(new TelematicProviderRegistry());
}

test('telematic service normalizes identity account location sensor and event helper values', function () {
    $service   = fleetOpsTelematicService();
    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid'      => 'telematic-uuid',
        'public_id' => 'telematic_public',
        'provider'  => 'flespi',
    ], true);

    $device = new Device();
    $device->setRawAttributes([
        'uuid'      => 'device-uuid',
        'device_id' => 'device-1',
    ], true);

    expect(fleetOpsTelematicServiceInvoke($service, 'resolveExternalId', [['device_id' => 'device-1']]))->toBe('device-1')
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveExternalId', [['external_id' => 'external-1']]))->toBe('external-1')
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveExternalId', [['device_id' => '']]))->toBeNull()
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveProviderAccountId', [[], ['x-provider-account' => ['acct-header']]]))->toBe('acct-header')
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveProviderAccountId', [['organization' => ['id' => 'org-1']], []]))->toBe('org-1')
        ->and(fleetOpsTelematicServiceInvoke($service, 'normalizeLocation', [['lat' => '1.25', 'lng' => '103.75']]))->toBe([
            'latitude'  => 1.25,
            'longitude' => 103.75,
        ])
        ->and(fleetOpsTelematicServiceInvoke($service, 'normalizeLocation', [['latitude' => '2.5', 'longitude' => '104.5']]))->toBe([
            'latitude'  => 2.5,
            'longitude' => 104.5,
        ])
        ->and(fleetOpsTelematicServiceInvoke($service, 'normalizeLocation', [['lat' => '1.25']]))->toBeNull()
        ->and(fleetOpsTelematicServiceInvoke($service, 'defaultLocation'))->toBe(['latitude' => 0, 'longitude' => 0])
        ->and(fleetOpsTelematicServiceInvoke($service, 'makePositionData', [
            ['latitude' => 1.25, 'longitude' => 103.75],
            ['heading' => 90, 'speed' => 35, 'altitude' => 12],
        ]))->toBe([
            'latitude'  => 1.25,
            'longitude' => 103.75,
            'heading'   => 90,
            'bearing'   => 90,
            'speed'     => 35,
            'altitude'  => 12,
        ]);

    $eventKey = fleetOpsTelematicServiceInvoke($service, 'makeEventKey', [$telematic, [
        'device_id'   => 'device-1',
        'event_id'    => 'event-1',
        'event_type'  => 'ignition',
        'occurred_at' => '2026-07-23 10:00:00',
    ], $device]);

    expect($eventKey)->toBe(sha1('flespi|telematic_public|device-1|event-1|ignition|2026-07-23 10:00:00'))
        ->and(fleetOpsTelematicServiceInvoke($service, 'makeEventKey', [$telematic, ['device_id' => 'device-1'], $device]))->toBeNull()
        ->and(fleetOpsTelematicServiceInvoke($service, 'makeSensorIdentity', [['type' => 'fuel', 'name' => 'Tank'], $device]))
        ->toBe(sha1('device-uuid|fuel|Tank'));

    expect(fleetOpsTelematicServiceInvoke($service, 'normalizeRawSensorList', [[
        'fuel' => 88,
        'temp' => ['value' => 20, 'unit' => 'C'],
    ]]))->toBe([
        ['sensor_key' => 'fuel', 'name' => 'fuel', 'type' => 'fuel', 'value' => 88],
        ['sensor_key' => 'temp', 'name' => 'temp', 'value' => 20, 'unit' => 'C'],
    ]);
});

test('telematic service resolves timestamps online flags status windows and event signals', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));
    $service = fleetOpsTelematicService();
    $device  = new Device();

    expect(fleetOpsTelematicServiceInvoke($service, 'resolveTelemetryTimestamp', [['timestamp' => '2026-07-23 11:55:00']])?->toDateTimeString())
        ->toBe('2026-07-23 11:55:00')
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveTelemetryTimestamp', [['timestamp' => 'not-a-date']]))->toBeNull()
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveReportedOnline', [['online' => 'true']]))->toBeTrue()
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveReportedOnline', [['is_online' => 'false']]))->toBeFalse()
        ->and(fleetOpsTelematicServiceInvoke($service, 'resolveReportedOnline', [[]]))->toBeNull()
        ->and(fleetOpsTelematicServiceInvoke($service, 'connectionStatusForDevice', [$device, null, true]))->toBe('online')
        ->and(fleetOpsTelematicServiceInvoke($service, 'connectionStatusForDevice', [$device, null, null]))->toBe('never_connected')
        ->and(fleetOpsTelematicServiceInvoke($service, 'connectionStatusForDevice', [$device, Carbon::parse('2026-07-23 11:55:00'), null]))->toBe('online')
        ->and(fleetOpsTelematicServiceInvoke($service, 'connectionStatusForDevice', [$device, Carbon::parse('2026-07-23 11:30:00'), null]))->toBe('recently_offline')
        ->and(fleetOpsTelematicServiceInvoke($service, 'connectionStatusForDevice', [$device, Carbon::parse('2026-07-23 05:00:00'), null]))->toBe('offline')
        ->and(fleetOpsTelematicServiceInvoke($service, 'connectionStatusForDevice', [$device, Carbon::parse('2026-07-21 05:00:00'), null]))->toBe('long_offline')
        ->and(fleetOpsTelematicServiceInvoke($service, 'isProtectedDeviceStatus', ['maintenance']))->toBeTrue()
        ->and(fleetOpsTelematicServiceInvoke($service, 'isProtectedDeviceStatus', ['active']))->toBeFalse()
        ->and(fleetOpsTelematicServiceInvoke($service, 'hasEventSignal', [['location' => ['lat' => 1.25]]]))->toBeTrue()
        ->and(fleetOpsTelematicServiceInvoke($service, 'hasEventSignal', [['speed' => 0]]))->toBeTrue()
        ->and(fleetOpsTelematicServiceInvoke($service, 'hasEventSignal', [[]]))->toBeFalse();

    Carbon::setTestNow();
});

test('telematic service reconciles device telemetry without overwriting protected statuses', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));
    $service = fleetOpsTelematicService();

    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid'         => 'telematic-uuid',
        'public_id'    => 'telematic_public',
        'company_uuid' => 'company-uuid',
        'provider'     => 'flespi',
    ], true);

    $device         = new FleetOpsTelematicServiceDeviceFake([
        'status' => 'maintenance',
        'meta'   => ['existing' => 'kept'],
    ]);
    $device->exists = false;

    fleetOpsTelematicServiceInvoke($service, 'reconcileDeviceTelemetry', [$device, $telematic, [
        'device_id'          => 'device-1',
        'name'               => 'Truck Device',
        'model'              => 'FMB920',
        'provider'           => 'flespi',
        'imei'               => '123456789',
        'serial_number'      => 'SER-1',
        'firmware_version'   => '1.0.0',
        'location'           => ['lat' => 1.25, 'lng' => 103.75],
        'last_seen_at'       => '2026-07-23 11:59:00',
        'online'             => true,
        'speed'              => 42,
        'heading'            => 180,
        'altitude'           => 15,
        'odometer'           => 12345,
        'ignition'           => true,
        'fuel_level'         => 67,
        'meta'               => ['provider_label' => 'primary'],
    ]]);

    expect($device->company_uuid)->toBe('company-uuid')
        ->and($device->name)->toBe('Truck Device')
        ->and($device->model)->toBe('FMB920')
        ->and($device->provider)->toBe('flespi')
        ->and($device->internal_id)->toBe('device-1')
        ->and($device->imei)->toBe('123456789')
        ->and($device->serial_number)->toBe('SER-1')
        ->and($device->firmware_version)->toBe('1.0.0')
        ->and($device->last_position)->toBe(['latitude' => 1.25, 'longitude' => 103.75])
        ->and($device->last_online_at?->toDateTimeString())->toBe('2026-07-23 11:59:00')
        ->and($device->online)->toBeTrue()
        ->and($device->status)->toBe('maintenance')
        ->and($device->meta['existing'])->toBe('kept')
        ->and($device->meta['external_id'])->toBe('device-1')
        ->and($device->meta['provider_status'])->toBe(['online' => true])
        ->and($device->meta['telemetry_summary'])->toMatchArray([
            'last_seen_at' => '2026-07-23 11:59:00',
            'status'       => 'online',
            'speed'        => 42,
            'heading'      => 180,
            'altitude'     => 15,
            'odometer'     => 12345,
            'ignition'     => true,
            'fuel_level'   => 67,
        ])
        ->and($device->meta['provider_label'])->toBe('primary');

    $emptyDevice         = new FleetOpsTelematicServiceDeviceFake();
    $emptyDevice->exists = false;
    fleetOpsTelematicServiceInvoke($service, 'reconcileDeviceTelemetry', [$emptyDevice, $telematic, [
        'device_id' => 'device-2',
    ]]);

    expect($emptyDevice->name)->toBe('Unknown Device')
        ->and($emptyDevice->last_position)->toBe(['latitude' => 0, 'longitude' => 0])
        ->and($emptyDevice->status)->toBe('never_connected')
        ->and($emptyDevice->online)->toBeFalse();

    Carbon::setTestNow();
});
