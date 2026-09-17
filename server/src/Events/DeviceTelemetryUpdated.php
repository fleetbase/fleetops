<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Device;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class DeviceTelemetryUpdated implements ShouldBroadcast
{
    public bool $afterCommit       = true;
    public ?string $broadcastQueue = null;
    public string $companyUuid;
    public string $deviceUuid;
    public array $data;

    public function __construct(Device $device)
    {
        $this->companyUuid = $device->company_uuid;
        $this->deviceUuid  = $device->uuid;
        $this->data        = ['id' => $device->uuid, 'device_id' => $device->public_id, 'telemetry' => data_get($device->meta, 'telemetry', [])];
    }

    public function broadcastOn(): array
    {
        return [new Channel('company.' . $this->companyUuid), new Channel('device.' . $this->deviceUuid)];
    }

    public function broadcastAs(): string
    {
        return 'device.telemetry_updated';
    }

    public function broadcastWith(): array
    {
        return ['event' => $this->broadcastAs(), 'data' => $this->data];
    }
}
