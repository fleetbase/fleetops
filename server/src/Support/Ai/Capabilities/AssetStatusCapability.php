<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;

class AssetStatusCapability extends AbstractFleetOpsAICapability
{
    public function key(): string
    {
        return 'fleet-ops.asset_status';
    }

    public function label(): string
    {
        return 'Fleet-Ops asset status';
    }

    public function description(): string
    {
        return 'Summarizes Fleet-Ops vehicle, device, sensor, and telematic status counts.';
    }

    public function permissions(): array
    {
        return ['fleet-ops see driver', 'fleet-ops see vehicle', 'fleet-ops see device', 'fleet-ops see sensor', 'fleet-ops see telematic'];
    }

    public function resolve(AiTask $task): array
    {
        return [
            'drivers'    => $this->driverStatus(),
            'vehicles'   => $this->statusCounts(Vehicle::class, 'fleet-ops see vehicle'),
            'devices'    => $this->deviceStatus(),
            'sensors'    => $this->statusCounts(Sensor::class, 'fleet-ops see sensor'),
            'telematics' => $this->statusCounts(Telematic::class, 'fleet-ops see telematic'),
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return $this->containsAny($prompt, ['offline', 'online', 'asset status', 'driver status', 'vehicle status', 'device status', 'sensor status', 'telematic status', 'drivers down', 'devices down', 'vehicles down']);
    }

    protected function statusCounts(string $modelClass, string $permission): array
    {
        if (!$this->can($permission)) {
            return ['authorized' => false];
        }

        return [
            'authorized'       => true,
            'total'            => $this->totalForModel($modelClass),
            'counts_by_status' => $this->countsByStatusForModel($modelClass),
        ];
    }

    protected function deviceStatus(): array
    {
        if (!$this->can('fleet-ops see device')) {
            return ['authorized' => false];
        }

        return [
            'authorized'       => true,
            'total'            => $this->totalForModel(Device::class),
            'online'           => $this->onlineCountForModel(Device::class),
            'offline'          => $this->offlineCountForModel(Device::class),
            'counts_by_status' => $this->countsByStatusForModel(Device::class),
        ];
    }

    protected function driverStatus(): array
    {
        if (!$this->can('fleet-ops see driver')) {
            return ['authorized' => false];
        }

        return [
            'authorized'       => true,
            'total'            => $this->totalForModel(Driver::class),
            'online'           => $this->onlineCountForModel(Driver::class),
            'offline'          => $this->offlineCountForModel(Driver::class),
            'counts_by_status' => $this->countsByStatusForModel(Driver::class),
        ];
    }

    protected function totalForModel(string $modelClass): int
    {
        return $this->scopedQuery($modelClass)->count();
    }

    protected function onlineCountForModel(string $modelClass): int
    {
        return $this->scopedQuery($modelClass)->where('online', true)->count();
    }

    protected function offlineCountForModel(string $modelClass): int
    {
        return $this->scopedQuery($modelClass)->where(function ($query) {
            $query->where('online', false)->orWhereNull('online');
        })->count();
    }

    protected function countsByStatusForModel(string $modelClass): array
    {
        return $this->scopedQuery($modelClass)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();
    }

    /**
     * Company-scoped query with the user's IAM directives for listing the resource applied.
     */
    protected function scopedQuery(string $modelClass)
    {
        $permission = match ($modelClass) {
            Driver::class    => 'fleet-ops list driver',
            Vehicle::class   => 'fleet-ops list vehicle',
            Device::class    => 'fleet-ops list device',
            Sensor::class    => 'fleet-ops list sensor',
            Telematic::class => 'fleet-ops list telematic',
            default          => null,
        };

        $query = $modelClass::where('company_uuid', session('company'));

        return $permission ? $this->scopeToPermission($query, $permission) : $query;
    }
}
