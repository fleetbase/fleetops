<?php

test('hub routes are registered', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)
        ->toContain("['prefix' => 'hubs']")
        ->toContain("\$router->get('resources', 'HubController@resources');")
        ->toContain("\$router->get('maintenance', 'HubController@maintenance');");
});

test('hub controller exposes resources and maintenance contracts', function () {
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/HubController.php');

    expect($controller)
        ->toContain('public function resources(Request $request)')
        ->toContain('public function maintenance(Request $request)')
        ->toContain("'kpis'")
        ->toContain("'actions'")
        ->toContain("'sections'")
        ->toContain("'docs'");
});

test('resources hub action queue includes cross workflow resource actions', function () {
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/HubController.php');

    expect($controller)
        ->toContain("'drivers_without_vehicles'")
        ->toContain("'vehicles_without_drivers'")
        ->toContain("'vehicles_without_devices'")
        ->toContain("'unattached_devices'")
        ->toContain("'resource_issues'")
        ->toContain("'overdue_vehicle_schedules'")
        ->toContain("'open_resource_work_orders'")
        ->toContain("'low_stock_parts'")
        ->toContain("'unmatched_fuel_transactions'")
        ->toContain("'assign_vehicles_to_drivers', 'Assign vehicles to drivers'")
        ->toContain("'assign_drivers_to_vehicles', 'Assign drivers to vehicles'")
        ->toContain("'attach_devices_to_vehicles', 'Attach devices to vehicles'")
        ->toContain("'prepare_vehicle_maintenance', 'Prepare upcoming maintenance'")
        ->toContain("'close_overdue_work_orders', 'Close overdue work orders'")
        ->toContain("'replenish_low_stock_parts', 'Replenish low-stock parts'")
        ->toContain("'review_unmatched_fuel_transactions', 'Review unmatched fuel transactions'")
        ->toContain("'add_operating_places', 'Add operating places'")
        ->toContain("'add_service_vendors', 'Add vendors for service work'")
        ->toContain("'Core resources look ready'");
});

test('resource action target filters are supported', function () {
    $deviceFilter  = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/DeviceFilter.php');
    $driverFilter  = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/DriverFilter.php');
    $vehicleFilter = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/VehicleFilter.php');

    expect($deviceFilter)
        ->toContain('public function attachmentState')
        ->toContain("attachmentState === 'unattached'")
        ->toContain('public function status')
        ->toContain('public function telematic');

    expect($driverFilter)->toContain("\$vehicle === 'unassigned'");
    expect($vehicleFilter)->toContain("\$driverId === 'unassigned'");
});
