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
        return ['fleet-ops see vehicle', 'fleet-ops see device', 'fleet-ops see sensor', 'fleet-ops see telematic'];
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
            'total'            => $modelClass::where('company_uuid', session('company'))->count(),
            'counts_by_status' => $modelClass::where('company_uuid', session('company'))
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
        ];
    }

    protected function deviceStatus(): array
    {
        if (!$this->can('fleet-ops see device')) {
            return ['authorized' => false];
        }

        return [
            'authorized'       => true,
            'total'            => Device::where('company_uuid', session('company'))->count(),
            'online'           => Device::where('company_uuid', session('company'))->where('online', true)->count(),
            'offline'          => Device::where('company_uuid', session('company'))->where(function ($query) {
                $query->where('online', false)->orWhereNull('online');
            })->count(),
            'counts_by_status' => Device::where('company_uuid', session('company'))
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
        ];
    }

    protected function driverStatus(): array
    {
        if (!$this->can('fleet-ops see driver')) {
            return ['authorized' => false];
        }

        return [
            'authorized'       => true,
            'total'            => Driver::where('company_uuid', session('company'))->count(),
            'online'           => Driver::where('company_uuid', session('company'))->where('online', true)->count(),
            'offline'          => Driver::where('company_uuid', session('company'))->where(function ($query) {
                $query->where('online', false)->orWhereNull('online');
            })->count(),
            'counts_by_status' => Driver::where('company_uuid', session('company'))
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
        ];
    }
}
