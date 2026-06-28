<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;

class SearchResourcesCapability extends AbstractFleetOpsAICapability
{
    public function key(): string
    {
        return 'fleet-ops.search_resources';
    }

    public function label(): string
    {
        return 'Search Fleet-Ops resources';
    }

    public function description(): string
    {
        return 'Finds relevant Fleet-Ops orders, vehicles, drivers, work orders, maintenances, devices, sensors, and telematics.';
    }

    public function permissions(): array
    {
        return [
            'fleet-ops see order',
            'fleet-ops see vehicle',
            'fleet-ops see driver',
            'fleet-ops see work-order',
            'fleet-ops see maintenance',
            'fleet-ops see device',
            'fleet-ops see sensor',
            'fleet-ops see telematic',
        ];
    }

    public function resolve(AiTask $task): array
    {
        $prompt = (string) $task->prompt;
        $terms  = $this->searchTerms($prompt);

        return [
            'query_terms' => $terms,
            'results'     => array_filter([
                'orders'       => $this->orders($terms),
                'vehicles'     => $this->vehicles($terms),
                'drivers'      => $this->drivers($terms),
                'work_orders'  => $this->workOrders($terms),
                'maintenances' => $this->maintenances($terms),
                'devices'      => $this->devices($terms),
                'sensors'      => $this->sensors($terms),
                'telematics'   => $this->telematics($terms),
            ]),
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return $this->containsAny($prompt, ['find', 'show', 'open', 'look up', 'tell me about', 'status of', 'order', 'vehicle', 'driver', 'work order', 'maintenance', 'device', 'sensor', 'telematic']);
    }

    protected function orders(array $terms): array
    {
        if (!$this->can('fleet-ops see order')) {
            return [];
        }

        return Order::with(['transaction', 'trackingNumber'])
            ->where('company_uuid', session('company'))
            ->where(function ($query) use ($terms) {
                $this->whereLikeAny($query, ['public_id', 'internal_id', 'uuid', 'status', 'type'], $terms);
                $query->orWhereHas('trackingNumber', fn ($tracking) => $this->whereLikeAny($tracking, ['tracking_number', 'barcode'], $terms));
            })
            ->limit(5)
            ->get()
            ->map(fn (Order $order) => [
                'id'                  => $order->public_id,
                'uuid'                => $order->uuid,
                'tracking'            => $order->tracking,
                'status'              => $order->status,
                'type'                => $order->type,
                'transaction_amount'  => $order->transaction_amount,
                'transaction_currency' => $order->transaction_currency,
                'route'               => 'console.fleet-ops.operations.orders.index.details',
                'models'              => [$order->public_id ?: $order->uuid],
            ])
            ->values()
            ->all();
    }

    protected function vehicles(array $terms): array
    {
        if (!$this->can('fleet-ops see vehicle')) {
            return [];
        }

        return Vehicle::where('company_uuid', session('company'))
            ->where(fn ($query) => $this->whereLikeAny($query, ['name', 'make', 'model', 'plate_number', 'vin', 'public_id', 'internal_id'], $terms))
            ->limit(5)
            ->get()
            ->map(fn (Vehicle $vehicle) => [
                'id'       => $vehicle->public_id,
                'uuid'     => $vehicle->uuid,
                'name'     => $vehicle->display_name ?: $vehicle->name,
                'plate'    => $vehicle->plate_number,
                'status'   => $vehicle->status,
                'route'    => 'console.fleet-ops.management.vehicles.index.details',
                'models'   => [$vehicle->public_id ?: $vehicle->uuid],
            ])
            ->values()
            ->all();
    }

    protected function drivers(array $terms): array
    {
        if (!$this->can('fleet-ops see driver')) {
            return [];
        }

        return Driver::with('user')
            ->where('company_uuid', session('company'))
            ->where(function ($query) use ($terms) {
                $this->whereLikeAny($query, ['public_id', 'uuid', 'drivers_license_number', 'status'], $terms);
                $query->orWhereHas('user', fn ($user) => $this->whereLikeAny($user, ['name', 'email', 'phone'], $terms));
            })
            ->limit(5)
            ->get()
            ->map(fn (Driver $driver) => [
                'id'     => $driver->public_id,
                'uuid'   => $driver->uuid,
                'name'   => data_get($driver, 'user.name'),
                'status' => $driver->status,
                'route'  => 'console.fleet-ops.management.drivers.index.details',
                'models' => [$driver->public_id ?: $driver->uuid],
            ])
            ->values()
            ->all();
    }

    protected function workOrders(array $terms): array
    {
        return $this->generic(WorkOrder::class, 'fleet-ops see work-order', ['public_id', 'uuid', 'code', 'subject', 'status', 'priority'], 'console.fleet-ops.maintenance.work-orders.index.details', $terms);
    }

    protected function maintenances(array $terms): array
    {
        return $this->generic(Maintenance::class, 'fleet-ops see maintenance', ['public_id', 'uuid', 'status', 'type', 'summary', 'notes'], 'console.fleet-ops.maintenance.maintenances.index.details', $terms);
    }

    protected function devices(array $terms): array
    {
        return $this->generic(Device::class, 'fleet-ops see device', ['public_id', 'uuid', 'name', 'device_id', 'imei', 'serial_number', 'status'], 'console.fleet-ops.connectivity.devices.index.details', $terms);
    }

    protected function sensors(array $terms): array
    {
        return $this->generic(Sensor::class, 'fleet-ops see sensor', ['public_id', 'uuid', 'name', 'internal_id', 'serial_number', 'imei', 'type', 'sensor_type', 'status'], 'console.fleet-ops.connectivity.sensors.index.details', $terms);
    }

    protected function telematics(array $terms): array
    {
        return $this->generic(Telematic::class, 'fleet-ops see telematic', ['public_id', 'uuid', 'name', 'provider', 'status'], 'console.fleet-ops.connectivity.telematics.details', $terms);
    }

    protected function generic(string $modelClass, string $permission, array $columns, string $route, array $terms): array
    {
        if (!$this->can($permission)) {
            return [];
        }

        return $modelClass::where('company_uuid', session('company'))
            ->where(fn ($query) => $this->whereLikeAny($query, $columns, $terms))
            ->limit(5)
            ->get()
            ->map(fn ($record) => [
                'id'     => $record->public_id,
                'uuid'   => $record->uuid,
                'name'   => $record->name ?? $record->subject ?? $record->summary ?? $record->description ?? null,
                'status' => $record->status ?? null,
                'route'  => $route,
                'models' => [$record->public_id ?: $record->uuid],
            ])
            ->values()
            ->all();
    }
}
