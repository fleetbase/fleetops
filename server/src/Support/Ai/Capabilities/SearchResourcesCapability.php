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

        if (empty($terms)) {
            return [
                'query_terms' => [],
                'results'     => [],
                'message'     => 'The prompt did not reference a specific Fleet-Ops record, so no records were searched.',
            ];
        }

        return $this->searchAll($terms);
    }

    /**
     * Search the given resource types (all when null) for the terms. A failing search is reported
     * without discarding the others.
     */
    protected function searchAll(array $terms, ?array $types = null): array
    {
        $results  = [];
        $failed   = [];
        $searches = [
            'orders'       => fn () => $this->orders($terms),
            'vehicles'     => fn () => $this->vehicles($terms),
            'drivers'      => fn () => $this->drivers($terms),
            'work_orders'  => fn () => $this->workOrders($terms),
            'maintenances' => fn () => $this->maintenances($terms),
            'devices'      => fn () => $this->devices($terms),
            'sensors'      => fn () => $this->sensors($terms),
            'telematics'   => fn () => $this->telematics($terms),
        ];

        if ($types !== null) {
            $searches = array_intersect_key($searches, array_flip($types));
        }

        foreach ($searches as $resource => $search) {
            try {
                $results[$resource] = $search();
            } catch (\Throwable $e) {
                $failed[] = $resource;
                $this->reportSearchFailure($resource, $e);
            }
        }

        $payload = [
            'query_terms' => $terms,
            'results'     => array_filter($results),
        ];

        if (!empty($failed)) {
            $payload['unavailable_search'] = $failed;
        }

        return $payload;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function reportSearchFailure(string $resource, \Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
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

        return $this->orderSearchQuery()
            ->where(function ($query) use ($terms) {
                $this->whereLikeAny($query, ['public_id', 'internal_id'], $terms);
                $query->orWhereHas('trackingNumber', fn ($tracking) => $this->whereLikeAny($tracking, ['tracking_number', 'barcode'], $terms));
            })
            ->limit(5)
            ->get()
            ->map(fn (Order $order) => [
                'id'                   => $order->public_id,
                'uuid'                 => $order->uuid,
                'tracking'             => $order->tracking,
                'status'               => $order->status,
                'type'                 => $order->type,
                'transaction_amount'   => $order->transaction_amount,
                'transaction_currency' => $order->transaction_currency,
                'route'                => 'console.fleet-ops.operations.orders.index.details',
                'models'               => [$order->public_id ?: $order->uuid],
            ])
            ->values()
            ->all();
    }

    protected function vehicles(array $terms): array
    {
        if (!$this->can('fleet-ops see vehicle')) {
            return [];
        }

        return $this->vehicleSearchQuery()
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

        return $this->driverSearchQuery()
            ->where(function ($query) use ($terms) {
                $this->whereLikeAny($query, ['public_id', 'internal_id', 'drivers_license_number'], $terms);
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
        return $this->generic(WorkOrder::class, 'fleet-ops see work-order', ['public_id', 'code', 'subject'], 'console.fleet-ops.maintenance.work-orders.index.details', $terms);
    }

    protected function maintenances(array $terms): array
    {
        return $this->generic(Maintenance::class, 'fleet-ops see maintenance', ['public_id', 'summary', 'notes'], 'console.fleet-ops.maintenance.maintenances.index.details', $terms);
    }

    protected function devices(array $terms): array
    {
        return $this->generic(Device::class, 'fleet-ops see device', ['public_id', 'name', 'device_id', 'imei', 'serial_number'], 'console.fleet-ops.connectivity.devices.index.details', $terms);
    }

    protected function sensors(array $terms): array
    {
        return $this->generic(Sensor::class, 'fleet-ops see sensor', ['public_id', 'name', 'internal_id', 'serial_number', 'imei'], 'console.fleet-ops.connectivity.sensors.index.details', $terms);
    }

    protected function telematics(array $terms): array
    {
        return $this->generic(Telematic::class, 'fleet-ops see telematic', ['public_id', 'name'], 'console.fleet-ops.connectivity.telematics.details', $terms);
    }

    protected function generic(string $modelClass, string $permission, array $columns, string $route, array $terms): array
    {
        if (!$this->can($permission)) {
            return [];
        }

        return $this->genericSearchQuery($modelClass)
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

    protected function orderSearchQuery()
    {
        return $this->scopeToPermission(
            Order::with(['transaction', 'trackingNumber'])->where('company_uuid', session('company')),
            'fleet-ops list order'
        );
    }

    protected function vehicleSearchQuery()
    {
        return $this->scopeToPermission(Vehicle::where('company_uuid', session('company')), 'fleet-ops list vehicle');
    }

    protected function driverSearchQuery()
    {
        return $this->scopeToPermission(Driver::with('user')->where('company_uuid', session('company')), 'fleet-ops list driver');
    }

    protected function genericSearchQuery(string $modelClass)
    {
        $permission = match ($modelClass) {
            WorkOrder::class   => 'fleet-ops list work-order',
            Maintenance::class => 'fleet-ops list maintenance',
            Device::class      => 'fleet-ops list device',
            Sensor::class      => 'fleet-ops list sensor',
            Telematic::class   => 'fleet-ops list telematic',
            default            => null,
        };

        $query = $modelClass::where('company_uuid', session('company'));

        return $permission ? $this->scopeToPermission($query, $permission) : $query;
    }
}
