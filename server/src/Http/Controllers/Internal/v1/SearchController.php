<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    private const SEARCH_TYPES = [
        'orders',
        'drivers',
        'vehicles',
        'fleets',
        'vendors',
        'contacts',
        'places',
        'issues',
        'fuel_reports',
        'fuel_transactions',
        'maintenance_schedules',
        'work_orders',
        'maintenances',
        'equipment',
        'parts',
        'fuel_providers',
        'telematics',
        'devices',
        'sensors',
        'events',
        'service_rates',
        'order_configs',
    ];

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) ($request->input('query') ?: $request->input('q')));
        $limit = max(1, min((int) $request->input('limit', 12), 24));

        if ($query === '') {
            return response()->json(['results' => []]);
        }

        $types        = $this->requestedTypes($request);
        $perTypeLimit = max(1, (int) ceil($limit / max(count($types), 1)));
        $results      = collect();

        foreach ($types as $type) {
            if (!$this->canSearchType($type)) {
                continue;
            }

            $results = $results->merge($this->searchType($type, $query, $perTypeLimit));
        }

        return response()->json([
            'results' => $results->take($limit)->values(),
        ]);
    }

    private function requestedTypes(Request $request): array
    {
        $types = $request->input('types', self::SEARCH_TYPES);

        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }

        if (!is_array($types)) {
            return self::SEARCH_TYPES;
        }

        $types = array_values(array_intersect($types, self::SEARCH_TYPES));

        return empty($types) ? self::SEARCH_TYPES : $types;
    }

    private function canSearchType(string $type): bool
    {
        $permissions = [
            'orders'                => 'fleet-ops see order',
            'drivers'               => 'fleet-ops see driver',
            'vehicles'              => 'fleet-ops see vehicle',
            'fleets'                => 'fleet-ops see fleet',
            'vendors'               => 'fleet-ops see vendor',
            'contacts'              => 'fleet-ops see contact',
            'places'                => 'fleet-ops see place',
            'issues'                => 'fleet-ops see issue',
            'fuel_reports'          => 'fleet-ops see fuel-report',
            'fuel_transactions'     => 'fleet-ops see fuel-report',
            'maintenance_schedules' => 'fleet-ops see maintenance-schedule',
            'work_orders'           => 'fleet-ops see work-order',
            'maintenances'          => 'fleet-ops see maintenance',
            'equipment'             => 'fleet-ops see equipment',
            'parts'                 => 'fleet-ops see part',
            'fuel_providers'        => 'fleet-ops see fuel-report',
            'telematics'            => 'fleet-ops see telematic',
            'devices'               => 'fleet-ops see device',
            'sensors'               => 'fleet-ops see sensor',
            'events'                => 'fleet-ops see device-event',
            'service_rates'         => 'fleet-ops see service-rate',
            'order_configs'         => 'fleet-ops see order-config',
        ];

        $user = Auth::getUserFromSession();

        if ($user?->isAdmin()) {
            return true;
        }

        return Auth::can($permissions[$type]);
    }

    private function searchType(string $type, string $query, int $limit): Collection
    {
        return match ($type) {
            'orders'                => $this->searchOrders($query, $limit),
            'drivers'               => $this->searchDrivers($query, $limit),
            'vehicles'              => $this->searchVehicles($query, $limit),
            'fleets'                => $this->searchFleets($query, $limit),
            'vendors'               => $this->searchVendors($query, $limit),
            'contacts'              => $this->searchContacts($query, $limit),
            'places'                => $this->searchPlaces($query, $limit),
            'issues'                => $this->searchIssues($query, $limit),
            'fuel_reports'          => $this->searchFuelReports($query, $limit),
            'fuel_transactions'     => $this->searchFuelTransactions($query, $limit),
            'maintenance_schedules' => $this->searchMaintenanceSchedules($query, $limit),
            'work_orders'           => $this->searchWorkOrders($query, $limit),
            'maintenances'          => $this->searchMaintenances($query, $limit),
            'equipment'             => $this->searchEquipment($query, $limit),
            'parts'                 => $this->searchParts($query, $limit),
            'fuel_providers'        => $this->searchFuelProviders($query, $limit),
            'telematics'            => $this->searchTelematics($query, $limit),
            'devices'               => $this->searchDevices($query, $limit),
            'sensors'               => $this->searchSensors($query, $limit),
            'events'                => $this->searchEvents($query, $limit),
            'service_rates'         => $this->searchServiceRates($query, $limit),
            'order_configs'         => $this->searchOrderConfigs($query, $limit),
            default                 => collect(),
        };
    }

    private function searchOrders(string $query, int $limit): Collection
    {
        return Order::with('trackingNumber')
            ->where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'internal_id', 'uuid', 'status', 'type'], $query);
                $builder->orWhereHas('trackingNumber', function (Builder $trackingNumberBuilder) use ($query) {
                    $this->whereLike($trackingNumberBuilder, ['tracking_number', 'barcode'], $query);
                });
            })
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => [
                'label'       => $order->tracking ?: $order->public_id ?: $order->internal_id,
                'description' => $this->description($order->status, $order->type, $order->public_id, $order->internal_id),
                'icon'        => 'map-location-dot',
                'type'        => 'Order',
                'route'       => 'console.fleet-ops.operations.orders.index.details',
                'models'      => [$this->routeModel($order)],
                'breadcrumb'  => 'Fleet-Ops > Operations > Orders',
            ]);
    }

    private function searchDrivers(string $query, int $limit): Collection
    {
        return Driver::with('user')
            ->where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'drivers_license_number', 'status'], $query);
                $builder->orWhereHas('user', function (Builder $userBuilder) use ($query) {
                    $this->whereLike($userBuilder, ['name', 'email', 'phone'], $query);
                });
            })
            ->limit($limit)
            ->get()
            ->map(fn (Driver $driver) => [
                'label'       => data_get($driver, 'user.name') ?: $driver->public_id,
                'description' => $this->description(data_get($driver, 'user.email'), data_get($driver, 'user.phone'), $driver->drivers_license_number),
                'icon'        => 'id-card',
                'type'        => 'Driver',
                'route'       => 'console.fleet-ops.management.drivers.index.details',
                'models'      => [$this->routeModel($driver)],
                'breadcrumb'  => 'Fleet-Ops > Resources > Drivers',
            ]);
    }

    private function searchVehicles(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Vehicle::class, ['name', 'description', 'make', 'model', 'year', 'internal_id', 'plate_number', 'vin', 'serial_number', 'call_sign', 'public_id', 'uuid'], $query, $limit, fn (Vehicle $vehicle) => [
            'label'       => $vehicle->display_name ?: $vehicle->name ?: $vehicle->public_id,
            'description' => $this->description($vehicle->plate_number, $vehicle->vin, $vehicle->status),
            'icon'        => 'truck',
            'type'        => 'Vehicle',
            'route'       => 'console.fleet-ops.management.vehicles.index.details',
            'models'      => [$this->routeModel($vehicle)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Vehicles',
        ]);
    }

    private function searchFleets(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Fleet::class, ['name', 'public_id', 'uuid'], $query, $limit, fn (Fleet $fleet) => [
            'label'       => $fleet->name ?: $fleet->public_id,
            'description' => $this->description($fleet->public_id),
            'icon'        => 'user-group',
            'type'        => 'Fleet',
            'route'       => 'console.fleet-ops.management.fleets.index.details',
            'models'      => [$this->routeModel($fleet)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Fleets',
        ]);
    }

    private function searchVendors(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Vendor::class, ['name', 'email', 'phone', 'business_id', 'public_id', 'uuid'], $query, $limit, fn (Vendor $vendor) => [
            'label'       => $vendor->name ?: $vendor->public_id,
            'description' => $this->description($vendor->email, $vendor->phone, $vendor->business_id),
            'icon'        => 'warehouse',
            'type'        => 'Vendor',
            'route'       => 'console.fleet-ops.management.vendors.index.details',
            'models'      => [$this->routeModel($vendor)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Vendors',
        ]);
    }

    private function searchContacts(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Contact::class, ['name', 'email', 'phone', 'public_id', 'uuid'], $query, $limit, fn (Contact $contact) => [
            'label'       => $contact->name ?: $contact->public_id,
            'description' => $this->description($contact->email, $contact->phone, $contact->type),
            'icon'        => 'address-book',
            'type'        => 'Contact',
            'route'       => 'console.fleet-ops.management.contacts.index.details',
            'models'      => [$this->routeModel($contact)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Contacts',
        ]);
    }

    private function searchPlaces(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Place::class, ['name', 'street1', 'street2', 'country', 'province', 'district', 'city', 'postal_code', 'phone', 'public_id', 'uuid'], $query, $limit, fn (Place $place) => [
            'label'       => $place->name ?: $place->public_id,
            'description' => $this->description($place->street1, $place->city, $place->country),
            'icon'        => 'location-dot',
            'type'        => 'Place',
            'route'       => 'console.fleet-ops.management.places.index.details',
            'models'      => [$this->routeModel($place)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Places',
        ]);
    }

    private function searchIssues(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Issue::class, ['public_id', 'uuid', 'issue_id', 'category', 'type', 'report', 'title', 'priority', 'status'], $query, $limit, fn (Issue $issue) => [
            'label'       => $issue->title ?: $issue->public_id,
            'description' => $this->description($issue->status, $issue->priority, $issue->type),
            'icon'        => 'triangle-exclamation',
            'type'        => 'Issue',
            'route'       => 'console.fleet-ops.management.issues.index.details',
            'models'      => [$this->routeModel($issue)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Issues',
        ]);
    }

    private function searchFuelReports(string $query, int $limit): Collection
    {
        return $this->searchGeneric(FuelReport::class, ['public_id', 'uuid', 'report', 'status', 'currency'], $query, $limit, fn (FuelReport $report) => [
            'label'       => $report->public_id ?: $report->report,
            'description' => $this->description($report->status, $report->currency, $report->report),
            'icon'        => 'gas-pump',
            'type'        => 'Fuel Report',
            'route'       => 'console.fleet-ops.management.fuel-reports.index.details',
            'models'      => [$this->routeModel($report)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Fuel Reports',
        ]);
    }

    private function searchFuelTransactions(string $query, int $limit): Collection
    {
        return $this->searchGeneric(FuelProviderTransaction::class, ['public_id', 'provider', 'provider_transaction_id', 'vehicle_card_id', 'internal_number', 'plate_number', 'vin', 'serial_number', 'call_sign', 'station_name', 'trip_number', 'sync_status'], $query, $limit, fn (FuelProviderTransaction $transaction) => [
            'label'       => $transaction->public_id ?: $transaction->provider_transaction_id,
            'description' => $this->description($transaction->provider, $transaction->station_name, $transaction->sync_status),
            'icon'        => 'credit-card',
            'type'        => 'Fuel Transaction',
            'route'       => 'console.fleet-ops.management.fuel-transactions.index.details',
            'models'      => [$this->routeModel($transaction)],
            'breadcrumb'  => 'Fleet-Ops > Resources > Fuel Transactions',
        ]);
    }

    private function searchMaintenanceSchedules(string $query, int $limit): Collection
    {
        return $this->searchGeneric(MaintenanceSchedule::class, ['name', 'type', 'status', 'public_id', 'uuid'], $query, $limit, fn (MaintenanceSchedule $schedule) => [
            'label'       => $schedule->name ?: $schedule->public_id,
            'description' => $this->description($schedule->type, $schedule->status),
            'icon'        => 'calendar-alt',
            'type'        => 'Maintenance Schedule',
            'route'       => 'console.fleet-ops.maintenance.schedules.index.details',
            'models'      => [$this->routeModel($schedule)],
            'breadcrumb'  => 'Fleet-Ops > Maintenance > Schedules',
        ]);
    }

    private function searchWorkOrders(string $query, int $limit): Collection
    {
        return $this->searchGeneric(WorkOrder::class, ['code', 'subject', 'category', 'instructions', 'status', 'priority', 'public_id', 'uuid'], $query, $limit, fn (WorkOrder $workOrder) => [
            'label'       => $workOrder->code ?: $workOrder->subject,
            'description' => $this->description($workOrder->status, $workOrder->priority, $workOrder->category ?: $workOrder->subject),
            'icon'        => 'clipboard-list',
            'type'        => 'Work Order',
            'route'       => 'console.fleet-ops.maintenance.work-orders.index.details',
            'models'      => [$this->routeModel($workOrder)],
            'breadcrumb'  => 'Fleet-Ops > Maintenance > Work Orders',
        ]);
    }

    private function searchMaintenances(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Maintenance::class, ['summary', 'notes', 'type', 'status', 'priority', 'public_id', 'uuid'], $query, $limit, fn (Maintenance $maintenance) => [
            'label'       => $maintenance->summary ?: $maintenance->public_id,
            'description' => $this->description($maintenance->status, $maintenance->priority, $maintenance->type),
            'icon'        => 'history',
            'type'        => 'Maintenance',
            'route'       => 'console.fleet-ops.maintenance.maintenances.index.details',
            'models'      => [$this->routeModel($maintenance)],
            'breadcrumb'  => 'Fleet-Ops > Maintenance > Maintenances',
        ]);
    }

    private function searchEquipment(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Equipment::class, ['name', 'code', 'type', 'serial_number', 'manufacturer', 'model', 'public_id', 'uuid'], $query, $limit, fn (Equipment $equipment) => [
            'label'       => $equipment->name ?: $equipment->code,
            'description' => $this->description($equipment->type, $equipment->manufacturer, $equipment->model),
            'icon'        => 'trailer',
            'type'        => 'Equipment',
            'route'       => 'console.fleet-ops.maintenance.equipment.index.details',
            'models'      => [$this->routeModel($equipment)],
            'breadcrumb'  => 'Fleet-Ops > Maintenance > Equipment',
        ]);
    }

    private function searchParts(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Part::class, ['sku', 'name', 'manufacturer', 'model', 'serial_number', 'barcode', 'public_id', 'uuid'], $query, $limit, fn (Part $part) => [
            'label'       => $part->name ?: $part->sku,
            'description' => $this->description($part->sku, $part->manufacturer, $part->model),
            'icon'        => 'cog',
            'type'        => 'Part',
            'route'       => 'console.fleet-ops.maintenance.parts.index.details',
            'models'      => [$this->routeModel($part)],
            'breadcrumb'  => 'Fleet-Ops > Maintenance > Parts',
        ]);
    }

    private function searchFuelProviders(string $query, int $limit): Collection
    {
        return $this->searchGeneric(FuelProviderConnection::class, ['public_id', 'name', 'provider', 'status', 'environment', 'uuid'], $query, $limit, fn (FuelProviderConnection $connection) => [
            'label'       => $connection->name ?: $connection->provider,
            'description' => $this->description($connection->provider, $connection->status, $connection->environment),
            'icon'        => 'gas-pump',
            'type'        => 'Fuel Integration',
            'route'       => 'console.fleet-ops.connectivity.fuel-providers.details',
            'models'      => [$this->routeModel($connection)],
            'breadcrumb'  => 'Fleet-Ops > Connectivity > Fuel Integrations',
        ]);
    }

    private function searchTelematics(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Telematic::class, ['name', 'provider', 'model', 'serial_number', 'imei', 'public_id', 'uuid'], $query, $limit, fn (Telematic $telematic) => [
            'label'       => $telematic->name ?: $telematic->provider,
            'description' => $this->description($telematic->provider, $telematic->model, $telematic->serial_number),
            'icon'        => 'satellite-dish',
            'type'        => 'Telematic Provider',
            'route'       => 'console.fleet-ops.connectivity.telematics.details',
            'models'      => [$this->routeModel($telematic)],
            'breadcrumb'  => 'Fleet-Ops > Connectivity > Telematics',
        ]);
    }

    private function searchDevices(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Device::class, ['name', 'model', 'serial_number', 'manufacturer', 'device_id', 'internal_id', 'imei', 'public_id', 'uuid'], $query, $limit, fn (Device $device) => [
            'label'       => $device->name ?: $device->device_id,
            'description' => $this->description($device->manufacturer, $device->model, $device->serial_number),
            'icon'        => 'hard-drive',
            'type'        => 'Device',
            'route'       => 'console.fleet-ops.connectivity.devices.index.details',
            'models'      => [$this->routeModel($device)],
            'breadcrumb'  => 'Fleet-Ops > Connectivity > Devices',
        ]);
    }

    private function searchSensors(string $query, int $limit): Collection
    {
        return $this->searchGeneric(Sensor::class, ['name', 'type', 'internal_id', 'unit', 'public_id', 'uuid'], $query, $limit, fn (Sensor $sensor) => [
            'label'       => $sensor->name ?: $sensor->internal_id,
            'description' => $this->description($sensor->type, $sensor->unit),
            'icon'        => 'temperature-full',
            'type'        => 'Sensor',
            'route'       => 'console.fleet-ops.connectivity.sensors.index.details',
            'models'      => [$this->routeModel($sensor)],
            'breadcrumb'  => 'Fleet-Ops > Connectivity > Sensors',
        ]);
    }

    private function searchEvents(string $query, int $limit): Collection
    {
        return $this->searchGeneric(DeviceEvent::class, ['event_type', 'message', 'ident', 'code', 'provider', 'severity', 'public_id', 'uuid'], $query, $limit, fn (DeviceEvent $event) => [
            'label'       => $event->event_type ?: $event->public_id,
            'description' => $this->description($event->severity, $event->provider, $event->message),
            'icon'        => 'stream',
            'type'        => 'Device Event',
            'route'       => 'console.fleet-ops.connectivity.events.details',
            'models'      => [$this->routeModel($event)],
            'breadcrumb'  => 'Fleet-Ops > Connectivity > Events',
        ]);
    }

    private function searchServiceRates(string $query, int $limit): Collection
    {
        return $this->searchGeneric(ServiceRate::class, ['public_id', 'uuid', 'service_name', 'service_type', 'currency', 'algorithm', 'rate_calculation_method'], $query, $limit, fn (ServiceRate $serviceRate) => [
            'label'       => $serviceRate->service_name ?: $serviceRate->public_id,
            'description' => $this->description($serviceRate->service_type, $serviceRate->currency, $serviceRate->rate_calculation_method),
            'icon'        => 'file-invoice-dollar',
            'type'        => 'Service Rate',
            'route'       => 'console.fleet-ops.operations.service-rates.index.details',
            'models'      => [$this->routeModel($serviceRate)],
            'breadcrumb'  => 'Fleet-Ops > Operations > Service Rates',
        ]);
    }

    private function searchOrderConfigs(string $query, int $limit): Collection
    {
        return $this->searchGeneric(OrderConfig::class, ['name', 'description', 'key', 'namespace', 'status', 'public_id', 'uuid'], $query, $limit, fn (OrderConfig $orderConfig) => [
            'label'       => $orderConfig->name ?: $orderConfig->public_id,
            'description' => $this->description($orderConfig->status, $orderConfig->description, $orderConfig->namespace),
            'icon'        => 'diagram-project',
            'type'        => 'Order Config',
            'route'       => 'console.fleet-ops.operations.order-config',
            'queryParams' => ['query' => $query],
            'breadcrumb'  => 'Fleet-Ops > Operations > Order Config',
        ]);
    }

    private function searchGeneric(string $modelClass, array $columns, string $query, int $limit, callable $mapper): Collection
    {
        return $modelClass::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($columns, $query) {
                $this->whereLike($builder, $columns, $query);
            })
            ->limit($limit)
            ->get()
            ->map($mapper);
    }

    private function whereLike(Builder $builder, array $columns, string $query): void
    {
        $like = '%' . Str::replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $builder->{$method}($column, 'like', $like);
        }
    }

    private function routeModel($model): ?string
    {
        return $model->public_id ?: $model->uuid;
    }

    private function description(...$parts): string
    {
        return trim(implode(' ', array_filter(array_map(fn ($part) => is_scalar($part) ? (string) $part : null, $parts))));
    }
}
