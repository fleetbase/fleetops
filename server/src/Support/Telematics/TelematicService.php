<?php

namespace Fleetbase\FleetOps\Support\Telematics;

use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
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
        ]);
        $telematic->save();

        return $jobId;
    }

    /**
     * Link a device to a telematic.
     */
    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        $externalId = $deviceData['device_id'] ?? $deviceData['external_id'] ?? null;

        $device = Device::firstOrNew([
            'telematic_uuid' => $telematic->uuid,
            'device_id'      => $externalId,
        ]);

        $device->company_uuid     = $telematic->company_uuid;
        $device->name             = $deviceData['name'] ?? $deviceData['device_name'] ?? 'Unknown Device';
        $device->model            = $deviceData['model'] ?? $deviceData['device_model'] ?? null;
        $device->provider         = $deviceData['provider'] ?? $deviceData['device_provider'] ?? $telematic->provider;
        $device->type             = $deviceData['type'] ?? null;
        $device->internal_id      = $deviceData['internal_id'] ?? $externalId;
        $device->imei             = $deviceData['imei'] ?? null;
        $device->imsi             = $deviceData['imsi'] ?? null;
        $device->serial_number    = $deviceData['serial_number'] ?? null;
        $device->firmware_version = $deviceData['firmware_version'] ?? null;
        $device->status           = $deviceData['status'] ?? $device->status ?? 'active';
        $device->online           = $deviceData['online'] ?? ($device->status === 'active');
        $device->last_online_at   = $deviceData['last_online_at'] ?? $deviceData['last_seen_at'] ?? now();
        $device->meta             = array_merge($device->meta ?? [], [
            'external_id' => $externalId,
        ], $deviceData['meta'] ?? []);

        $location = $this->normalizeLocation($deviceData['location'] ?? null);
        if ($location) {
            $device->last_position = $location;
        }

        $device->save();

        return $device;
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        if (!$device) {
            $device = $this->resolveDeviceForPayload($telematic, $eventData);
        }

        $event               = new DeviceEvent();
        $event->company_uuid = $telematic->company_uuid;
        $event->device_uuid  = $device?->uuid;
        $event->event_type   = $eventData['event_type'] ?? $eventData['type'] ?? 'telemetry_update';
        $event->severity     = $eventData['severity'] ?? 'info';
        $event->provider     = $telematic->provider;
        $event->ident        = $eventData['ident'] ?? $eventData['external_id'] ?? null;
        $event->code         = $eventData['code'] ?? null;
        $event->state        = $eventData['state'] ?? null;
        $event->reason       = $eventData['reason'] ?? null;
        $event->payload      = $eventData['payload'] ?? $eventData['meta'] ?? $eventData;
        $event->meta         = array_merge($eventData['meta'] ?? [], [
            'telematic_uuid' => $telematic->uuid,
            'occurred_at'    => $eventData['occurred_at'] ?? null,
            'speed'          => $eventData['speed'] ?? null,
            'heading'        => $eventData['heading'] ?? null,
            'odometer'       => $eventData['odometer'] ?? null,
            'ignition'       => $eventData['ignition'] ?? null,
            'fuel_level'     => $eventData['fuel_level'] ?? null,
        ]);

        $location = $this->normalizeLocation($eventData['location'] ?? null);
        if ($location) {
            $event->location = $location;
        }

        $event->save();

        return $event;
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Sensor
    {
        if (!$device) {
            $device = $this->resolveDeviceForPayload($telematic, $sensorData);
        }

        $sensor = Sensor::firstOrNew([
            'telematic_uuid' => $telematic->uuid,
            'device_uuid'    => $device?->uuid,
            'type'           => $sensorData['type'] ?? $sensorData['sensor_type'] ?? 'generic',
            'internal_id'    => $sensorData['internal_id'] ?? $sensorData['external_id'] ?? null,
        ]);

        $sensor->company_uuid     = $telematic->company_uuid;
        $sensor->name             = $sensorData['name'] ?? $sensorData['sensor_type'] ?? $sensor->type ?? 'Sensor';
        $sensor->unit             = $sensorData['unit'] ?? null;
        $sensor->last_value       = isset($sensorData['value']) ? (string) $sensorData['value'] : $sensor->last_value;
        $sensor->last_reading_at  = $sensorData['recorded_at'] ?? now();
        $sensor->status           = $sensorData['status'] ?? 'active';
        $sensor->meta             = array_merge($sensor->meta ?? [], $sensorData['meta'] ?? []);

        $location = $this->normalizeLocation($sensorData['location'] ?? null);
        if ($location) {
            $sensor->last_position = $location;
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
        $externalId = $payload['device_id'] ?? $payload['external_id'] ?? $payload['ident'] ?? null;

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
}
