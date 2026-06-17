<?php

test('vehicle updates preserve driver assignment unless driver input is explicit', function () {
    $internalController = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/VehicleController.php');
    $publicController   = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/VehicleController.php');
    $vehicleModel       = file_get_contents(dirname(__DIR__) . '/src/Models/Vehicle.php');

    expect($internalController)
        ->toContain('hasDriverInput($request)')
        ->toContain('syncDriverAssignment($vehicle, $this->driverIdentifierFromRequest($request))')
        ->toContain("Arr::has(\$payload, 'vehicle.driver_uuid')")
        ->and($publicController)
        ->toContain("\$request->exists('driver')")
        ->toContain('$vehicle->unassignDriver()')
        ->and($vehicleModel)
        ->toContain('public function unassignDriver(): self')
        ->toContain("Driver::where('vehicle_uuid', \$this->uuid)->update(['vehicle_uuid' => null])");
});

test('driver vehicle assignment preserves license expiry', function () {
    $apiController      = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/DriverController.php');
    $internalController = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/DriverController.php');
    $driverModel        = file_get_contents(dirname(__DIR__) . '/src/Models/Driver.php');
    $driverResource     = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Driver.php');

    expect($apiController)
        ->not->toContain('normalizeDriverUpdateInput')
        ->not->toContain("array_key_exists('license_expiry', \$input)")
        ->and($internalController)
        ->not->toContain('normalizeDriverUpdateInput')
        ->toContain('public function assignVehicle(Request $request, string $id): JsonResponse')
        ->toContain('$driver->assignVehicle($vehicle)')
        ->not->toContain("'license_expiry' => null")
        ->and($driverModel)
        ->toContain('public function setLicenseExpiryAttribute($value): void')
        ->toContain("!empty(\$this->getOriginal('license_expiry'))")
        ->toContain("Carbon::parse(\$value)->toDateString()")
        ->toContain('public function assignVehicle(Vehicle $vehicle): self')
        ->toContain('$this->setVehicle($vehicle)')
        ->toContain('$this->save()')
        ->not->toContain('$this->license_expiry = null')
        ->and($driverResource)
        ->toContain("'license_expiry'                => \$this->formatDateOnly(\$this->license_expiry)")
        ->toContain('protected function formatDateOnly($date): ?string');
});

test('driver assigned-order workflows expose list and multi-unassign endpoints', function () {
    $routes     = file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/DriverController.php');
    $resource   = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Driver.php');
    $index      = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Index/Driver.php');

    expect($routes)
        ->toContain("\$router->get('{id}/assigned-orders', \$controller('assignedOrders'))")
        ->toContain("\$router->post('{id}/unassign-orders', \$controller('unassignOrders'))")
        ->and($controller)
        ->toContain('public function assignedOrders(string $id): JsonResponse')
        ->toContain('public function unassignOrders(Request $request, string $id): JsonResponse')
        ->toContain("->where('driver_assigned_uuid', \$driver->uuid)")
        ->toContain("->orderByRaw('uuid = ? desc', [\$driver->current_job_uuid])")
        ->toContain("\$request->validate(['orders' => ['required', 'array', 'min:1']])")
        ->toContain("->where('driver_assigned_uuid', \$driver->uuid)")
        ->toContain("->whereIn('uuid', \$selectedOrderUuids)")
        ->toContain("->update(['driver_assigned_uuid' => null])")
        ->toContain("if (\$driver->current_job_uuid && \$orders->contains('uuid', \$driver->current_job_uuid))")
        ->toContain("\$driver->update(['current_job_uuid' => null])")
        ->and($resource)
        ->toContain("'assigned_orders_count'")
        ->toContain("'current_order_reference'")
        ->and($index)
        ->toContain("'assigned_orders_count'");
});

test('vehicle assigned-order workflows expose list and multi-unassign endpoints without clearing driver vehicle assignment', function () {
    $routes     = file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/VehicleController.php');
    $resource   = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Vehicle.php');
    $index      = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Index/Vehicle.php');

    expect($routes)
        ->toContain("\$router->get('{id}/assigned-orders', \$controller('assignedOrders'))")
        ->toContain("\$router->post('{id}/unassign-orders', \$controller('unassignOrders'))")
        ->and($controller)
        ->toContain('public function assignedOrders(string $id): JsonResponse')
        ->toContain('public function unassignOrders(Request $request, string $id): JsonResponse')
        ->toContain("->where('vehicle_assigned_uuid', \$vehicle->uuid)")
        ->toContain("\$request->validate(['orders' => ['required', 'array', 'min:1']])")
        ->toContain("->whereIn('uuid', \$selectedOrderUuids)")
        ->toContain("->update(['vehicle_assigned_uuid' => null])")
        ->not->toContain("->update(['vehicle_uuid' => null])")
        ->and($resource)
        ->toContain("'assigned_orders_count'")
        ->toContain("'current_order_reference'")
        ->and($index)
        ->toContain("'assigned_orders_count'");
});
