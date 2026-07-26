<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SearchController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

function fleetopsSearchControllerMethod(string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod(SearchController::class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

class FleetOpsSearchGenericProbe extends SearchController
{
    public array $calls         = [];
    public array $modelsByClass = [];

    protected function searchGeneric(string $modelClass, array $columns, string $query, int $limit, callable $mapper): Collection
    {
        $this->calls[] = [$modelClass, $columns, $query, $limit];
        $model         = $this->modelsByClass[$modelClass] ?? fleetopsSearchModel($modelClass);

        return collect([$mapper($model)]);
    }
}

function fleetopsSearchModel(string $modelClass)
{
    $model = new $modelClass();
    $model->setRawAttributes([
        'uuid'                    => 'model-uuid',
        'public_id'               => 'model_public',
        'name'                    => 'Model Name',
        'description'             => 'Description',
        'email'                   => 'ops@example.test',
        'phone'                   => '+15555550123',
        'business_id'             => 'business-id',
        'street1'                 => '1 Main St',
        'city'                    => 'Singapore',
        'country'                 => 'Singapore',
        'issue_id'                => 'issue-id',
        'category'                => 'safety',
        'type'                    => 'inspection',
        'report'                  => 'Fuel report text',
        'title'                   => 'Flat tire',
        'priority'                => 'high',
        'status'                  => 'open',
        'currency'                => 'SGD',
        'provider'                => 'provider',
        'provider_transaction_id' => 'txn-123',
        'station_name'            => 'Fuel Station',
        'sync_status'             => 'synced',
        'code'                    => 'WO-1',
        'subject'                 => 'Brake check',
        'instructions'            => 'Inspect brakes',
        'summary'                 => 'Maintenance summary',
        'notes'                   => 'Maintenance notes',
        'manufacturer'            => 'Acme',
        'model'                   => 'MX',
        'serial_number'           => 'SN-1',
        'sku'                     => 'SKU-1',
        'barcode'                 => 'BAR-1',
        'environment'             => 'sandbox',
        'imei'                    => 'IMEI-1',
        'device_id'               => 'device-1',
        'internal_id'             => 'internal-1',
        'unit'                    => 'c',
        'event_type'              => 'ignition',
        'message'                 => 'Engine on',
        'ident'                   => 'ident-1',
        'severity'                => 'info',
        'service_name'            => 'Same Day',
        'service_type'            => 'delivery',
        'rate_calculation_method' => 'distance',
        'key'                     => 'transport',
        'namespace'               => 'fleet-ops',
        'make'                    => 'Toyota',
        'year'                    => '2025',
        'plate_number'            => 'SG-1234',
        'vin'                     => 'VIN123',
    ], true);

    return $model;
}

test('search controller returns an empty result set for blank queries', function () {
    $controller = new SearchController();

    $response = $controller->search(new Request(['query' => '   ']));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['results' => []]);
});

test('search controller normalizes requested types from strings arrays and invalid values', function () {
    $controller     = new SearchController();
    $requestedTypes = fleetopsSearchControllerMethod('requestedTypes');

    expect($requestedTypes->invoke($controller, new Request(['types' => 'orders, drivers,invalid, vehicles'])))->toBe(['orders', 'drivers', 'vehicles'])
        ->and($requestedTypes->invoke($controller, new Request(['types' => ['places', 'not-real', 'devices']])))->toBe(['places', 'devices'])
        ->and($requestedTypes->invoke($controller, new Request(['types' => ['not-real']])))->toContain('orders', 'drivers', 'vehicles', 'order_configs')
        ->and($requestedTypes->invoke($controller, new Request(['types' => new stdClass()])))->toContain('orders', 'drivers', 'vehicles', 'order_configs');
});

test('search controller falls back to an empty collection for unknown search types', function () {
    $controller = new SearchController();
    $searchType = fleetopsSearchControllerMethod('searchType');

    $result = $searchType->invoke($controller, 'unknown', 'needle', 5);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->all())->toBe([]);
});

test('search controller route model and description helpers normalize display values', function () {
    $controller  = new SearchController();
    $routeModel  = fleetopsSearchControllerMethod('routeModel');
    $description = fleetopsSearchControllerMethod('description');

    expect($routeModel->invoke($controller, (object) ['public_id' => 'public-id', 'uuid' => 'uuid']))->toBe('public-id')
        ->and($routeModel->invoke($controller, (object) ['public_id' => null, 'uuid' => 'uuid']))->toBe('uuid')
        ->and($description->invoke($controller, ' active ', null, ['ignored'], 42, false))->toBe('active  42');
});

test('search controller generic resource mappers return console navigation results', function (string $method, string $modelClass, array $expected) {
    $controller = new FleetOpsSearchGenericProbe();
    $search     = fleetopsSearchControllerMethod($method);

    $result = $search->invoke($controller, 'needle', 3)->first();

    expect($result)->toMatchArray($expected)
        ->and($result['models'] ?? null)->toBe(['model_public'])
        ->and($controller->calls[0][0])->toBe($modelClass)
        ->and($controller->calls[0][2])->toBe('needle')
        ->and($controller->calls[0][3])->toBe(3);
})->with([
    'vehicles' => ['searchVehicles', Vehicle::class, [
        'label'      => 'Model Name',
        'icon'       => 'truck',
        'type'       => 'Vehicle',
        'route'      => 'console.fleet-ops.management.vehicles.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Vehicles',
    ]],
    'fleets' => ['searchFleets', Fleet::class, [
        'label'      => 'Model Name',
        'icon'       => 'user-group',
        'type'       => 'Fleet',
        'route'      => 'console.fleet-ops.management.fleets.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Fleets',
    ]],
    'vendors' => ['searchVendors', Vendor::class, [
        'label'      => 'Model Name',
        'icon'       => 'warehouse',
        'type'       => 'Vendor',
        'route'      => 'console.fleet-ops.management.vendors.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Vendors',
    ]],
    'contacts' => ['searchContacts', Contact::class, [
        'label'      => 'Model Name',
        'icon'       => 'address-book',
        'type'       => 'Contact',
        'route'      => 'console.fleet-ops.management.contacts.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Contacts',
    ]],
    'places' => ['searchPlaces', Place::class, [
        'label'      => 'Model Name',
        'icon'       => 'location-dot',
        'type'       => 'Place',
        'route'      => 'console.fleet-ops.management.places.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Places',
    ]],
    'issues' => ['searchIssues', Issue::class, [
        'label'      => 'Flat tire',
        'icon'       => 'triangle-exclamation',
        'type'       => 'Issue',
        'route'      => 'console.fleet-ops.management.issues.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Issues',
    ]],
    'fuel reports' => ['searchFuelReports', FuelReport::class, [
        'label'      => 'model_public',
        'icon'       => 'gas-pump',
        'type'       => 'Fuel Report',
        'route'      => 'console.fleet-ops.management.fuel-reports.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Fuel Reports',
    ]],
    'fuel transactions' => ['searchFuelTransactions', FuelProviderTransaction::class, [
        'label'      => 'model_public',
        'icon'       => 'credit-card',
        'type'       => 'Fuel Transaction',
        'route'      => 'console.fleet-ops.management.fuel-transactions.index.details',
        'breadcrumb' => 'Fleet-Ops > Resources > Fuel Transactions',
    ]],
    'maintenance schedules' => ['searchMaintenanceSchedules', MaintenanceSchedule::class, [
        'label'      => 'Model Name',
        'icon'       => 'calendar-alt',
        'type'       => 'Maintenance Schedule',
        'route'      => 'console.fleet-ops.maintenance.schedules.index.details',
        'breadcrumb' => 'Fleet-Ops > Maintenance > Schedules',
    ]],
    'work orders' => ['searchWorkOrders', WorkOrder::class, [
        'label'      => 'WO-1',
        'icon'       => 'clipboard-list',
        'type'       => 'Work Order',
        'route'      => 'console.fleet-ops.maintenance.work-orders.index.details',
        'breadcrumb' => 'Fleet-Ops > Maintenance > Work Orders',
    ]],
    'maintenances' => ['searchMaintenances', Maintenance::class, [
        'label'      => 'Maintenance summary',
        'icon'       => 'history',
        'type'       => 'Maintenance',
        'route'      => 'console.fleet-ops.maintenance.maintenances.index.details',
        'breadcrumb' => 'Fleet-Ops > Maintenance > Maintenances',
    ]],
    'equipment' => ['searchEquipment', Equipment::class, [
        'label'      => 'Model Name',
        'icon'       => 'trailer',
        'type'       => 'Equipment',
        'route'      => 'console.fleet-ops.maintenance.equipment.index.details',
        'breadcrumb' => 'Fleet-Ops > Maintenance > Equipment',
    ]],
    'parts' => ['searchParts', Part::class, [
        'label'      => 'Model Name',
        'icon'       => 'cog',
        'type'       => 'Part',
        'route'      => 'console.fleet-ops.maintenance.parts.index.details',
        'breadcrumb' => 'Fleet-Ops > Maintenance > Parts',
    ]],
    'fuel providers' => ['searchFuelProviders', FuelProviderConnection::class, [
        'label'      => 'Model Name',
        'icon'       => 'gas-pump',
        'type'       => 'Fuel Integration',
        'route'      => 'console.fleet-ops.connectivity.fuel-providers.details',
        'breadcrumb' => 'Fleet-Ops > Connectivity > Fuel Integrations',
    ]],
    'telematics' => ['searchTelematics', Telematic::class, [
        'label'      => 'Model Name',
        'icon'       => 'satellite-dish',
        'type'       => 'Telematic Provider',
        'route'      => 'console.fleet-ops.connectivity.telematics.details',
        'breadcrumb' => 'Fleet-Ops > Connectivity > Telematics',
    ]],
    'devices' => ['searchDevices', Device::class, [
        'label'      => 'Model Name',
        'icon'       => 'hard-drive',
        'type'       => 'Device',
        'route'      => 'console.fleet-ops.connectivity.devices.index.details',
        'breadcrumb' => 'Fleet-Ops > Connectivity > Devices',
    ]],
    'sensors' => ['searchSensors', Sensor::class, [
        'label'      => 'Model Name',
        'icon'       => 'temperature-full',
        'type'       => 'Sensor',
        'route'      => 'console.fleet-ops.connectivity.sensors.index.details',
        'breadcrumb' => 'Fleet-Ops > Connectivity > Sensors',
    ]],
    'events' => ['searchEvents', DeviceEvent::class, [
        'label'      => 'ignition',
        'icon'       => 'stream',
        'type'       => 'Device Event',
        'route'      => 'console.fleet-ops.connectivity.events.details',
        'breadcrumb' => 'Fleet-Ops > Connectivity > Events',
    ]],
    'service rates' => ['searchServiceRates', ServiceRate::class, [
        'label'      => 'Same Day',
        'icon'       => 'file-invoice-dollar',
        'type'       => 'Service Rate',
        'route'      => 'console.fleet-ops.operations.service-rates.index.details',
        'breadcrumb' => 'Fleet-Ops > Operations > Service Rates',
    ]],
]);

test('search controller order config mapper returns query params instead of model identifiers', function () {
    $controller = new FleetOpsSearchGenericProbe();
    $search     = fleetopsSearchControllerMethod('searchOrderConfigs');

    $result = $search->invoke($controller, 'needle', 4)->first();

    expect($result)->toMatchArray([
        'label'       => 'Model Name',
        'icon'        => 'diagram-project',
        'type'        => 'Order Config',
        'route'       => 'console.fleet-ops.operations.order-config',
        'queryParams' => ['query' => 'needle'],
        'breadcrumb'  => 'Fleet-Ops > Operations > Order Config',
    ])
        ->and($result)->not->toHaveKey('models')
        ->and($controller->calls[0][0])->toBe(OrderConfig::class)
        ->and($controller->calls[0][3])->toBe(4);
});
