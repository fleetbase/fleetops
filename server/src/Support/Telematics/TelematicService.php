<?php

namespace Fleetbase\FleetOps\Support\Telematics;

use Fleetbase\FleetOps\Jobs\SyncDevicesJob;
use Fleetbase\FleetOps\Jobs\TestConnectionJob;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
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
        $telematic->credentials  = Crypt::encryptString(json_encode($data['credentials']));
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
            $telematic->credentials = Crypt::encryptString(json_encode($data['credentials']));
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
            $job = new TestConnectionJob($telematic);
            dispatch($job);

            return ['job_id' => $job->getJobId(), 'message' => 'Connection test queued'];
        }

        $provider = $this->registry->resolve($telematic->provider);
        $provider->connect($telematic);

        $credentials = json_decode(Crypt::decryptString($telematic->credentials), true);

        return $provider->testConnection($credentials);
    }

    /**
     * Discover devices from a provider.
     *
     * @return string Job ID
     */
    public function discoverDevices(Telematic $telematic, array $options = []): string
    {
        $job = new SyncDevicesJob($telematic, $options);
        dispatch($job);

        return $job->getJobId();
    }

    /**
     * Link a device to a telematic.
     */
    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        $device = Device::firstOrNew([
            'telematic_uuid' => $telematic->uuid,
            'external_id'    => $deviceData['external_id'],
        ]);

        $device->device_name     = $deviceData['device_name'] ?? 'Unknown Device';
        $device->device_model    = $deviceData['device_model'] ?? null;
        $device->device_provider = $telematic->provider;
        $device->status          = $deviceData['status'] ?? 'active';
        $device->meta            = array_merge($device->meta ?? [], $deviceData['meta'] ?? []);

        $device->save();

        return $device;
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
                $q->where('device_name', 'like', "%{$filters['search']}%")
                  ->orWhere('external_id', 'like', "%{$filters['search']}%");
            });
        }

        return $query->get();
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
        return json_decode(Crypt::decryptString($telematic->credentials), true);
    }
}
