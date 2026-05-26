<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Seeders\Testing\Concerns\SeedsTestingData;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConnectivitySeeder extends Seeder
{
    use SeedsTestingData;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        DB::transaction(function () use ($company) {
            $this->purgeSeedData();
            $telematics = $this->seedTelematics($company);
            $devices    = $this->seedDevices($company, $telematics);
            $this->seedSensors($company, $devices);
            $this->seedDeviceEvents($company, $devices);
            $this->seedGeofenceEvents($company);

            $this->command?->info('Seeded FleetOps testing connectivity fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        if ($zone = $this->seededZone('cbd_zone')) {
            GeofenceEventLog::where('geofence_uuid', $zone->uuid)
                ->whereIn('event_type', ['entered', 'dwelled', 'exited'])
                ->delete();
        }

        $this->purgeModel(DeviceEvent::class);
        $this->purgeModel(Sensor::class);
        $this->purgeModel(Device::class);
        $this->purgeModel(Telematic::class);
    }

    protected function seedTelematics(Company $company): array
    {
        $telematics = [
            'telematic_samsara'    => ['Samsara Sandbox', 'samsara', 'VG34', 'TM-SAM-001', 'active'],
            'telematic_calculated' => ['Calculated Provider', 'calculated', 'SIM', 'TM-CALC-001', 'connected'],
        ];

        $models = [];
        foreach ($telematics as $seedId => [$name, $provider, $model, $serial, $status]) {
            $models[$seedId] = $this->createRecord(Telematic::class, [
                '_key'             => $this->fixtureKey($seedId),
                'company_uuid'     => $company->uuid,
                'name'             => $name,
                'provider'         => $provider,
                'model'            => $model,
                'serial_number'    => $serial,
                'firmware_version' => '2026.01',
                'status'           => $status,
                'imei'             => '3569380356438' . ($provider === 'samsara' ? '01' : '02'),
                'last_seen_at'     => $this->timestamp(1),
                'last_metrics'     => ['signal' => 88, 'battery' => 94],
                'config'           => ['fixture' => true],
                'credentials'      => ['token' => 'testing-fixture-redacted'],
                'meta'             => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedDevices(Company $company, array $telematics): array
    {
        $vehicleCentral = $this->seededModel(Vehicle::class, 'van_central');
        $vehicleEast    = $this->seededModel(Vehicle::class, 'van_east');
        $devices        = [
            'device_van_central' => ['CEN-01 OBD', 'OBD-II', 'Fleetbase Labs', 'DEV-CEN-001', 'telematic_samsara', $vehicleCentral, 1.2821, 103.8510, true, 'online'],
            'device_van_east'    => ['EAS-01 Reefer', 'ColdChain-GW', 'Fleetbase Labs', 'DEV-EAST-001', 'telematic_calculated', $vehicleEast, 1.3522, 103.9442, true, 'online'],
        ];

        $models = [];
        foreach ($devices as $seedId => [$name, $model, $manufacturer, $serial, $telematicSeedId, $vehicle, $lat, $lng, $online, $status]) {
            $models[$seedId] = $this->createRecord(Device::class, [
                '_key'              => $this->fixtureKey($seedId),
                'company_uuid'      => $company->uuid,
                'telematic_uuid'    => $telematics[$telematicSeedId]?->uuid,
                'attachable_uuid'   => $vehicle?->uuid,
                'attachable_type'   => Vehicle::class,
                'type'              => 'tracker',
                'device_id'         => strtoupper($seedId),
                'internal_id'       => strtoupper($seedId),
                'name'              => $name,
                'model'             => $model,
                'manufacturer'      => $manufacturer,
                'serial_number'     => $serial,
                'last_position'     => $this->point($lat, $lng),
                'installation_date' => $this->timestamp(-720)->toDateString(),
                'last_online_at'    => $this->timestamp(2),
                'online'            => $online,
                'status'            => $status,
                'data_frequency'    => 60,
                'notes'             => 'FleetOps testing fixture device.',
                'data'              => ['speed_kmh' => 28, 'heading' => 92],
                'options'           => ['fixture' => true],
                'meta'              => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedSensors(Company $company, array $devices): void
    {
        $sensors = [
            'sensor_temperature' => ['device_van_east', 'Cargo Temperature', 'temperature', 'C', -2, 8, '4.2'],
            'sensor_door'        => ['device_van_central', 'Cargo Door', 'door', 'state', 0, 1, 'closed'],
        ];

        foreach ($sensors as $seedId => [$deviceSeedId, $name, $sensorType, $unit, $min, $max, $lastValue]) {
            $device = $devices[$deviceSeedId] ?? null;
            $this->createRecord(Sensor::class, [
                '_key'                => $this->fixtureKey($seedId),
                'company_uuid'        => $company->uuid,
                'device_uuid'         => $device?->uuid,
                'name'                => $name,
                'sensor_type'         => $sensorType,
                'type'                => $sensorType,
                'unit'                => $unit,
                'min_threshold'       => $min,
                'max_threshold'       => $max,
                'threshold_inclusive' => true,
                'last_position'       => $device?->last_position ?? $this->point(0, 0),
                'last_reading_at'     => $this->timestamp(2),
                'last_value'          => $lastValue,
                'sensorable_type'     => Device::class,
                'sensorable_uuid'     => $device?->uuid,
                'status'              => 'active',
                'meta'                => $this->meta($seedId),
            ]);
        }
    }

    protected function seedDeviceEvents(Company $company, array $devices): void
    {
        $events = [
            'event_ignition_on'         => ['device_van_central', 'ignition_on', 'info', 'Vehicle ignition on'],
            'event_temperature_warning' => ['device_van_east', 'temperature_threshold', 'warning', 'Cargo temperature near threshold'],
        ];

        foreach ($events as $seedId => [$deviceSeedId, $eventType, $severity, $reason]) {
            $device = $devices[$deviceSeedId] ?? null;
            $this->createRecord(DeviceEvent::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'device_uuid'  => $device?->uuid,
                'payload'      => ['fixture' => true, 'event' => $eventType],
                'meta'         => $this->meta($seedId),
                'event_type'   => $eventType,
                'severity'     => $severity,
                'ident'        => strtoupper($seedId),
                'protocol'     => 'testing',
                'provider'     => 'fleetops-testing',
                'state'        => $severity,
                'code'         => strtoupper($eventType),
                'reason'       => $reason,
                'occurred_at'  => $this->timestamp(2),
            ]);
        }
    }

    protected function seedGeofenceEvents(Company $company): void
    {
        $driver  = $this->seededModel(Driver::class, 'driver_ava');
        $vehicle = $this->seededModel(Vehicle::class, 'van_central');
        $order   = $this->seededModel(Order::class, 'order_dispatched');
        $zone    = $this->seededZone('cbd_zone');

        if (!$driver || !$zone) {
            return;
        }

        foreach (['entered', 'dwelled', 'exited'] as $index => $eventType) {
            $this->createRecord(GeofenceEventLog::class, [
                'company_uuid'             => $company->uuid,
                'driver_uuid'              => $driver->uuid,
                'vehicle_uuid'             => $vehicle?->uuid,
                'order_uuid'               => $order?->uuid,
                'geofence_uuid'            => $zone->uuid,
                'geofence_type'            => 'zone',
                'geofence_name'            => 'Marina Bay',
                'event_type'               => $eventType,
                'latitude'                 => 1.2821,
                'longitude'                => 103.8510,
                'speed_kmh'                => $eventType === 'dwelled' ? 0 : 22,
                'dwell_duration_minutes'   => $eventType === 'dwelled' ? 18 : null,
                'occurred_at'              => $this->timestamp($index),
            ]);
        }
    }
}
