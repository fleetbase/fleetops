<?php

namespace Fleetbase\FleetOps\Support\Telematics;

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Class TelematicService.
 *
 * Business logic for telematics management.
 * Handles CRUD operations, connection testing, and device discovery.
 */
class TelematicService
{
    protected const PROTECTED_DEVICE_STATUSES = [
        'disabled',
        'decommissioned',
        'maintenance',
        'provisioning',
        'pending_activation',
    ];

    protected TelematicProviderRegistry $registry;

    public function __construct(TelematicProviderRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Create a new telematic integration.
     *
     * @throws ValidationException
     */
    public function create(array $data): Telematic
    {
        // Validate provider exists
        $providerKey = $data['provider_key'];
        $descriptor  = $this->registry->findByKey($providerKey);

        if (!$descriptor) {
            throw ValidationException::withMessages(['provider_key' => ['Invalid provider key.']]);
        }

        // Validate credentials against provider schema
        $this->validateCredentials($data['credentials'] ?? [], $descriptor->requiredFields);

        // Test connection if requested
        if ($data['test_connection'] ?? false) {
            $provider = $this->registry->resolve($providerKey);
            $result   = $provider->testConnection($data['credentials']);

            if (!$result['success']) {
                throw ValidationException::withMessages(['credentials' => [$result['message']]]);
            }
        }

        // Create telematic record
        $telematic               = new Telematic();
        $telematic->company_uuid = session('company');
        $telematic->name         = $data['name'];
        $telematic->provider     = $providerKey;
        $telematic->credentials  = $this->encryptCredentials($data['credentials']);
        $telematic->status       = 'active';
        $telematic->meta         = $data['meta'] ?? [];
        $telematic->save();

        return $telematic;
    }

    /**
     * Update a telematic integration.
     */
    public function update(Telematic $telematic, array $data): Telematic
    {
        if (isset($data['name'])) {
            $telematic->name = $data['name'];
        }

        if (isset($data['credentials'])) {
            $descriptor = $this->registry->findByKey($telematic->provider);
            $this->validateCredentials($data['credentials'], $descriptor->requiredFields);
            $telematic->credentials = $this->encryptCredentials($data['credentials']);
        }

        if (isset($data['status'])) {
            $telematic->status = $data['status'];
        }

        if (isset($data['meta'])) {
            $telematic->meta = array_merge($telematic->meta ?? [], $data['meta']);
        }

        $telematic->save();

        return $telematic;
    }

    /**
     * Delete a telematic integration.
     */
    public function delete(Telematic $telematic): bool
    {
        return $telematic->delete();
    }

    /**
     * Test connection to a provider.
     *
     * @return array|string
     */
    public function testConnection(Telematic $telematic, bool $async = false)
    {
        if ($async) {
            $jobId = (string) Str::uuid();
            dispatch(new TestTelematicConnectionJob($telematic, $jobId));

            return ['job_id' => $jobId, 'message' => 'Connection test queued'];
        }

        $provider = $this->registry->resolve($telematic->provider);
        $provider->connect($telematic);

        $credentials = $this->getCredentials($telematic);

        $result = $provider->testConnection($credentials);
        $this->recordConnectionTest($telematic, $result);

        return $result;
    }

    /**
     * Discover devices from a provider.
     *
     * @return string Job ID
     */
    public function discoverDevices(Telematic $telematic, array $options = []): string
    {
        $jobId = (string) Str::uuid();
        dispatch(new SyncTelematicDevicesJob($telematic, $options, $jobId));

        $telematic->status = 'synchronizing';
        $telematic->meta   = array_merge($telematic->meta ?? [], [
            'last_sync_job_id'     => $jobId,
            'last_sync_started_at' => now()->toDateTimeString(),
            'last_sync_result'     => 'queued',
            'last_sync_error'      => null,
        ]);
        $telematic->save();

        return $jobId;
    }

    /**
     * Link a device to a telematic.
     */
    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        $externalId = $this->resolveExternalId($deviceData);

        if (!$externalId) {
            throw ValidationException::withMessages(['device_id' => ['Provider device identity is required to link a telematics device.']]);
        }

        $device = Device::firstOrNew([
            'telematic_uuid' => $telematic->uuid,
            'device_id'      => $externalId,
        ]);

        $this->reconcileDeviceTelemetry($device, $telematic, array_merge($deviceData, [
            'external_id' => $externalId,
        ]));

        $device->save();

        return $device;
    }

    public function ingestDeviceSnapshot(Telematic $telematic, TelematicProviderInterface $provider, array $payload): array
    {
        $device = $this->linkDevice($telematic, $provider->normalizeDevice($payload));

        $event = null;
        try {
            $eventData = $provider->normalizeEvent($payload);
            if ($this->hasEventSignal($eventData)) {
                $event = $this->storeDeviceEvent($telematic, $eventData, $device);
            }
        } catch (\Throwable) {
            $event = null;
        }

        return [
            'device'  => $device,
            'event'   => $event,
            'sensors' => $this->storeSnapshotSensors($telematic, $provider, $payload, $device),
        ];
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        if (!$device) {
            $device = $this->resolveDeviceForPayload($telematic, $eventData);
        }

        $eventKey            = $this->makeEventKey($telematic, $eventData, $device);
        $event               = $eventKey ? DeviceEvent::firstOrNew(['_key' => $eventKey]) : new DeviceEvent();
        $wasRecentlyCreated  = !$event->exists;
        $event->company_uuid = $telematic->company_uuid;
        $event->device_uuid  = $device?->uuid;
        $event->event_type   = $eventData['event_type'] ?? $eventData['type'] ?? 'telemetry_update';
        $event->severity     = $eventData['severity'] ?? 'info';
        $event->message      = $eventData['message'] ?? $eventData['reason'] ?? null;
        $event->provider     = $telematic->provider;
        $event->ident        = $eventData['ident'] ?? $eventData['event_id'] ?? $eventData['external_event_id'] ?? $eventData['external_id'] ?? null;
        $event->code         = $eventData['code'] ?? null;
        $event->state        = $eventData['state'] ?? null;
        $event->reason       = $eventData['reason'] ?? null;
        $event->occurred_at  = $eventData['occurred_at'] ?? $eventData['recorded_at'] ?? $eventData['timestamp'] ?? null;
        $event->data         = $eventData['data'] ?? array_filter([
            'speed'      => $eventData['speed'] ?? null,
            'heading'    => $eventData['heading'] ?? null,
            'altitude'   => $eventData['altitude'] ?? null,
            'odometer'   => $eventData['odometer'] ?? null,
            'ignition'   => $eventData['ignition'] ?? null,
            'fuel_level' => $eventData['fuel_level'] ?? null,
        ], fn ($value) => $value !== null);
        $event->payload      = $eventData['payload'] ?? $eventData['meta'] ?? $eventData;
        $event->_key         = $eventKey;
        $event->meta         = array_merge($eventData['meta'] ?? [], [
            'telematic_uuid'    => $telematic->uuid,
            'telematic_id'      => $telematic->public_id,
            'provider_event_id' => $event->ident,
            'occurred_at'       => $eventData['occurred_at'] ?? null,
            'speed'             => $eventData['speed'] ?? null,
            'heading'           => $eventData['heading'] ?? null,
            'altitude'          => $eventData['altitude'] ?? null,
            'odometer'          => $eventData['odometer'] ?? null,
            'ignition'          => $eventData['ignition'] ?? null,
            'fuel_level'        => $eventData['fuel_level'] ?? null,
        ]);

        $location = $this->normalizeLocation($eventData['location'] ?? null);
        if ($location) {
            $event->location = $location;
        }

        $event->save();
        $this->applyDeviceEventTelemetry($event, $eventData, $device, $wasRecentlyCreated, $telematic);

        return $event;
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Sensor
    {
        if (!$device) {
            $device = $this->resolveDeviceForPayload($telematic, $sensorData);
        }

        $sensorIdentity = $sensorData['internal_id'] ?? $sensorData['sensor_id'] ?? $sensorData['external_id'] ?? null;
        if (!$sensorIdentity && !$device) {
            throw ValidationException::withMessages(['sensor_id' => ['Provider sensor identity or device identity is required to link a telematics sensor.']]);
        }

        $sensor = Sensor::firstOrNew([
            'telematic_uuid' => $telematic->uuid,
            'device_uuid'    => $device?->uuid,
            'type'           => $sensorData['type'] ?? $sensorData['sensor_type'] ?? 'generic',
            'internal_id'    => $sensorIdentity ?? $this->makeSensorIdentity($sensorData, $device),
        ]);

        $sensor->company_uuid     = $telematic->company_uuid;
        $sensor->name             = $sensorData['name'] ?? $sensorData['sensor_type'] ?? $sensor->type ?? 'Sensor';
        $sensor->unit             = $sensorData['unit'] ?? null;
        $sensor->last_value       = isset($sensorData['value']) ? (string) $sensorData['value'] : $sensor->last_value;
        $sensor->last_reading_at  = $sensorData['recorded_at'] ?? $sensorData['last_reading_at'] ?? $sensor->last_reading_at ?? now();
        $sensor->status           = $sensorData['status'] ?? 'active';
        $sensor->meta             = array_merge($sensor->meta ?? [], $sensorData['meta'] ?? []);

        $location = $this->normalizeLocation($sensorData['location'] ?? null);
        if ($location) {
            $sensor->last_position = $location;
        } elseif (!$sensor->exists || !$sensor->last_position) {
            $sensor->last_position = $this->defaultLocation();
        }

        $sensor->save();

        return $sensor;
    }

    /**
     * Get devices for a telematic.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDevices(Telematic $telematic, array $filters = [])
    {
        $query = Device::where('telematic_uuid', $telematic->uuid);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('device_id', 'like', "%{$filters['search']}%")
                    ->orWhere('internal_id', 'like', "%{$filters['search']}%")
                    ->orWhere('imei', 'like', "%{$filters['search']}%");
            });
        }

        return $query->get();
    }

    public function recordConnectionTest(Telematic $telematic, array $result): void
    {
        $telematic->status = ($result['success'] ?? false) ? 'connected' : 'error';
        $telematic->meta   = array_merge($telematic->meta ?? [], [
            'last_connection_test' => now()->toDateTimeString(),
            'last_test_result'     => ($result['success'] ?? false) ? 'success' : 'failed',
            'last_error'           => ($result['success'] ?? false) ? null : ($result['message'] ?? 'Connection test failed'),
            'last_test_metadata'   => $result['metadata'] ?? [],
        ]);
        $telematic->save();
    }

    /**
     * Validate credentials against provider schema.
     *
     * @throws ValidationException
     */
    protected function validateCredentials(array $credentials, array $schema): void
    {
        $rules = [];

        foreach ($schema as $field) {
            $fieldRules = [];

            if ($field['required'] ?? true) {
                $fieldRules[] = 'required';
            }

            if (isset($field['validation'])) {
                $fieldRules[] = $field['validation'];
            } else {
                $fieldRules[] = 'string';
            }

            $rules[$field['name']] = implode('|', $fieldRules);
        }

        $validator = Validator::make($credentials, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Get decrypted credentials for a telematic.
     */
    public function getCredentials(Telematic $telematic): array
    {
        if (is_array($telematic->credentials)) {
            return $telematic->credentials;
        }

        $credentials = $telematic->credentials;
        if (!$credentials) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($credentials), true) ?? [];
        } catch (\Throwable) {
            return json_decode($credentials, true) ?? [];
        }
    }

    protected function encryptCredentials(array $credentials): string
    {
        return Crypt::encryptString(json_encode($credentials));
    }

    protected function resolveDeviceForPayload(Telematic $telematic, array $payload): ?Device
    {
        $externalId = $this->resolveExternalId($payload);

        if (!$externalId) {
            return null;
        }

        return Device::where('telematic_uuid', $telematic->uuid)
            ->where(function ($query) use ($externalId) {
                $query->where('device_id', $externalId)
                    ->orWhere('internal_id', $externalId)
                    ->orWhere('imei', $externalId);
            })
            ->first();
    }

    public function resolveWebhookTelematic(string $providerKey, array $payload = [], array $headers = [], ?string $integrationId = null): ?Telematic
    {
        $query = Telematic::where('provider', $providerKey);

        if ($integrationId) {
            return (clone $query)
                ->where(function ($query) use ($integrationId) {
                    $query->where('public_id', $integrationId)->orWhere('uuid', $integrationId);
                })
                ->first();
        }

        $providerAccountId = $this->resolveProviderAccountId($payload, $headers);
        if ($providerAccountId) {
            $matches = (clone $query)
                ->where(function ($query) use ($providerAccountId) {
                    $query->where('meta->provider_account_id', $providerAccountId)
                        ->orWhere('meta->account_id', $providerAccountId)
                        ->orWhere('meta->organization_id', $providerAccountId)
                        ->orWhere('meta->customer_id', $providerAccountId);
                })
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        $deviceId = $this->resolveExternalId($payload);
        if ($deviceId) {
            $matches = (clone $query)
                ->whereHas('device', function ($query) use ($deviceId) {
                    $query->where('device_id', $deviceId)
                        ->orWhere('internal_id', $deviceId)
                        ->orWhere('imei', $deviceId);
                })
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    protected function normalizeLocation(?array $location): mixed
    {
        if (!$location) {
            return null;
        }

        $lat = $location['lat'] ?? $location['latitude'] ?? null;
        $lng = $location['lng'] ?? $location['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['latitude' => (float) $lat, 'longitude' => (float) $lng];
    }

    protected function reconcileDeviceTelemetry(Device $device, ?Telematic $telematic, array $payload): void
    {
        $externalId = $this->resolveExternalId($payload);

        if ($telematic) {
            $device->company_uuid = $telematic->company_uuid;
        }

        $this->setDeviceAttributeIfPresent($device, 'name', $payload['name'] ?? $payload['device_name'] ?? (!$device->exists ? 'Unknown Device' : null));
        $this->setDeviceAttributeIfPresent($device, 'model', $payload['model'] ?? $payload['device_model'] ?? null);
        $this->setDeviceAttributeIfPresent($device, 'provider', $payload['provider'] ?? $payload['device_provider'] ?? $telematic?->provider);
        $this->setDeviceAttributeIfPresent($device, 'type', $payload['type'] ?? null);
        $this->setDeviceAttributeIfPresent($device, 'internal_id', $payload['internal_id'] ?? $externalId);
        $this->setDeviceAttributeIfPresent($device, 'imei', $payload['imei'] ?? null);
        $this->setDeviceAttributeIfPresent($device, 'imsi', $payload['imsi'] ?? null);
        $this->setDeviceAttributeIfPresent($device, 'serial_number', $payload['serial_number'] ?? null);
        $this->setDeviceAttributeIfPresent($device, 'firmware_version', $payload['firmware_version'] ?? null);

        $location = $this->normalizeLocation($payload['location'] ?? null);
        if ($location) {
            $device->last_position = $location;
        } elseif (!$device->exists || !$device->last_position) {
            $device->last_position = $this->defaultLocation();
        }

        $lastSeen       = $this->resolveTelemetryTimestamp($payload);
        $reportedOnline = $this->resolveReportedOnline($payload);

        if (!$lastSeen && $reportedOnline === true) {
            $lastSeen = now();
        }

        if ($lastSeen) {
            $device->last_online_at = $lastSeen;
        }

        $connectionStatus = $this->connectionStatusForDevice($device, $lastSeen, $reportedOnline);
        $device->online   = $connectionStatus === 'online';

        if (!$this->isProtectedDeviceStatus($device->status)) {
            $device->status = $connectionStatus;
        }

        $device->meta = array_merge($device->meta ?? [], [
            'external_id'       => $externalId,
            'provider_status'   => array_filter([
                'status'    => $payload['status'] ?? null,
                'state'     => $payload['state'] ?? null,
                'active'    => $payload['active'] ?? data_get($payload, 'meta.active'),
                'online'    => $payload['online'] ?? null,
                'is_online' => $payload['is_online'] ?? null,
            ], fn ($value) => $value !== null),
            'telemetry_summary' => array_filter([
                'last_seen_at' => $lastSeen?->toDateTimeString(),
                'status'       => $connectionStatus,
                'speed'        => $payload['speed'] ?? null,
                'heading'      => $payload['heading'] ?? null,
                'altitude'     => $payload['altitude'] ?? null,
                'odometer'     => $payload['odometer'] ?? null,
                'ignition'     => $payload['ignition'] ?? null,
                'fuel_level'   => $payload['fuel_level'] ?? null,
            ], fn ($value) => $value !== null),
        ], $payload['meta'] ?? []);
    }

    protected function setDeviceAttributeIfPresent(Device $device, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $device->{$key} = $value;
    }

    protected function resolveTelemetryTimestamp(array $payload): ?Carbon
    {
        $value = $payload['last_online_at']
            ?? $payload['last_seen_at']
            ?? $payload['occurred_at']
            ?? $payload['recorded_at']
            ?? $payload['timestamp']
            ?? data_get($payload, 'meta.last_update.occurred_at')
            ?? data_get($payload, 'meta.occurred_at')
            ?? null;

        if (!$value) {
            return null;
        }

        try {
            return $value instanceof Carbon ? $value : Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveReportedOnline(array $payload): ?bool
    {
        $value = $payload['online'] ?? $payload['is_online'] ?? null;

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    protected function connectionStatusForDevice(Device $device, ?Carbon $lastSeen = null, ?bool $reportedOnline = null): string
    {
        if ($reportedOnline === true) {
            return 'online';
        }

        $lastSeen ??= $device->last_online_at;

        if (!$lastSeen) {
            return 'never_connected';
        }

        $minutesOffline = Carbon::parse($lastSeen)->diffInMinutes(now());

        if ($minutesOffline <= 10) {
            return 'online';
        }

        if ($minutesOffline <= 60) {
            return 'recently_offline';
        }

        if ($minutesOffline <= 1440) {
            return 'offline';
        }

        return 'long_offline';
    }

    protected function isProtectedDeviceStatus(?string $status): bool
    {
        return in_array($status, self::PROTECTED_DEVICE_STATUSES, true);
    }

    protected function applyDeviceEventTelemetry(DeviceEvent $event, array $eventData, ?Device $device = null, bool $wasRecentlyCreated = true, ?Telematic $telematic = null): void
    {
        $location = $this->normalizeLocation($eventData['location'] ?? null);

        $device ??= $event->device;
        if ($device) {
            $this->reconcileDeviceTelemetry($device, $telematic, $eventData);
            $device->save();
            $device->loadMissing('attachable');
        }

        if (!$location) {
            return;
        }

        $positionData = $this->makePositionData($location, $eventData);
        if ($wasRecentlyCreated && $positionData) {
            $event->createPosition($positionData);
        }

        $attachable = $device?->attachable;
        if ($attachable instanceof Vehicle) {
            $this->updateVehicleTelemetry($attachable, $location, $eventData, $event);
        }
    }

    protected function updateVehicleTelemetry(Vehicle $vehicle, array $location, array $eventData, DeviceEvent $event): void
    {
        $vehicle->location = new SpatialPoint($location['latitude'], $location['longitude']);
        $vehicle->online   = array_key_exists('online', $eventData) && $eventData['online'] !== null ? (bool) $eventData['online'] : true;

        if (array_key_exists('speed', $eventData) && $eventData['speed'] !== null) {
            $vehicle->speed = $eventData['speed'];
        }

        if (array_key_exists('heading', $eventData) && $eventData['heading'] !== null) {
            $vehicle->heading = $eventData['heading'];
        }

        if (array_key_exists('altitude', $eventData) && $eventData['altitude'] !== null) {
            $vehicle->altitude = $eventData['altitude'];
        }

        $vehicle->telematics = array_merge($vehicle->telematics ?? [], [
            'last_event_uuid'     => $event->uuid,
            'last_event_id'       => $event->public_id,
            'last_event_type'     => $event->event_type,
            'last_event_at'       => optional($event->occurred_at)->toDateTimeString() ?? now()->toDateTimeString(),
            'last_device_uuid'    => $event->device_uuid,
            'last_provider'       => $event->provider,
            'last_telemetry_data' => array_filter([
                'speed'      => $eventData['speed'] ?? null,
                'heading'    => $eventData['heading'] ?? null,
                'altitude'   => $eventData['altitude'] ?? null,
                'odometer'   => $eventData['odometer'] ?? null,
                'ignition'   => $eventData['ignition'] ?? null,
                'fuel_level' => $eventData['fuel_level'] ?? null,
            ], fn ($value) => $value !== null),
        ]);
        $vehicle->save();

        broadcast(new VehicleLocationChanged($vehicle, [
            'source'            => 'telematics',
            'device_event_uuid' => $event->uuid,
            'provider'          => $event->provider,
        ]));
    }

    protected function makePositionData(array $location, array $eventData): array
    {
        return array_filter([
            'latitude'  => $location['latitude'],
            'longitude' => $location['longitude'],
            'heading'   => $eventData['heading'] ?? null,
            'bearing'   => $eventData['heading'] ?? null,
            'speed'     => $eventData['speed'] ?? null,
            'altitude'  => $eventData['altitude'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function hasEventSignal(array $eventData): bool
    {
        return (bool) array_filter([
            $eventData['event_id'] ?? null,
            $eventData['external_event_id'] ?? null,
            $eventData['ident'] ?? null,
            $eventData['event_type'] ?? null,
            $eventData['occurred_at'] ?? null,
            $eventData['recorded_at'] ?? null,
            $eventData['timestamp'] ?? null,
            $eventData['location']['lat'] ?? null,
            $eventData['location']['latitude'] ?? null,
            $eventData['speed'] ?? null,
            $eventData['heading'] ?? null,
            $eventData['altitude'] ?? null,
            $eventData['odometer'] ?? null,
            $eventData['ignition'] ?? null,
            $eventData['fuel_level'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function storeSnapshotSensors(Telematic $telematic, TelematicProviderInterface $provider, array $payload, Device $device): int
    {
        $rawSensors = $payload['sensors'] ?? $payload['sensors_last_val'] ?? [];
        if (!is_array($rawSensors) || empty($rawSensors)) {
            return 0;
        }

        $stored     = 0;
        $externalId = $this->resolveExternalId($payload);

        foreach ($this->normalizeRawSensorList($rawSensors) as $rawSensor) {
            try {
                $sensorData = $provider->normalizeSensor(array_merge([
                    'device_id' => $externalId,
                ], $rawSensor));
                $sensorData['device_id'] ??= $externalId;

                $this->storeSensor($telematic, $sensorData, $device);
                $stored++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $stored;
    }

    protected function normalizeRawSensorList(array $rawSensors): array
    {
        if (array_is_list($rawSensors)) {
            return array_filter($rawSensors, fn ($sensor) => is_array($sensor));
        }

        return collect($rawSensors)
            ->map(function ($sensor, $name) {
                if (is_array($sensor)) {
                    return array_merge(['name' => $name], $sensor);
                }

                return [
                    'name'  => $name,
                    'type'  => $name,
                    'value' => $sensor,
                ];
            })
            ->values()
            ->all();
    }

    protected function defaultLocation(): array
    {
        return ['latitude' => 0, 'longitude' => 0];
    }

    protected function resolveExternalId(array $payload): ?string
    {
        $value = $payload['device_id']
            ?? $payload['external_id']
            ?? $payload['ident']
            ?? $payload['unit_id']
            ?? $payload['vehicle_id']
            ?? $payload['imei']
            ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected function resolveProviderAccountId(array $payload, array $headers = []): ?string
    {
        $headerValue = data_get($headers, 'x-provider-account.0')
            ?? data_get($headers, 'x-organization-id.0')
            ?? data_get($headers, 'x-customer-id.0');

        $value = $payload['provider_account_id']
            ?? $payload['account_id']
            ?? $payload['organization_id']
            ?? $payload['org_id']
            ?? $payload['customer_id']
            ?? data_get($payload, 'account.id')
            ?? data_get($payload, 'organization.id')
            ?? $headerValue;

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected function makeEventKey(Telematic $telematic, array $eventData, ?Device $device = null): ?string
    {
        $deviceId = $device?->device_id ?? $this->resolveExternalId($eventData);
        if (!$deviceId) {
            return null;
        }

        $providerEventId = $eventData['event_id'] ?? $eventData['external_event_id'] ?? $eventData['ident'] ?? $eventData['external_id'] ?? null;
        $occurredAt      = $eventData['occurred_at'] ?? $eventData['recorded_at'] ?? $eventData['timestamp'] ?? null;
        $eventType       = $eventData['event_type'] ?? $eventData['type'] ?? 'telemetry_update';

        if (!$providerEventId && !$occurredAt) {
            return null;
        }

        return sha1(implode('|', [
            $telematic->provider,
            $telematic->public_id ?? $telematic->uuid,
            $deviceId,
            $providerEventId,
            $eventType,
            $occurredAt,
        ]));
    }

    protected function makeSensorIdentity(array $sensorData, ?Device $device = null): string
    {
        return sha1(implode('|', [
            $device?->uuid ?? 'no-device',
            $sensorData['type'] ?? $sensorData['sensor_type'] ?? 'generic',
            $sensorData['name'] ?? $sensorData['unit'] ?? 'sensor',
        ]));
    }
}
