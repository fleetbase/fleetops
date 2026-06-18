<?php

test('operations monitor live route is registered on the internal fleetops surface', function () {
    $routes = file_get_contents(__DIR__ . '/../src/routes.php');

    expect($routes)
        ->toContain("\$router->get('operations-monitor', 'LiveController@operationsMonitor');")
        ->toContain("['prefix' => 'live']");
});

test('operations monitor endpoint returns a cached flat resource snapshot with id linked fleets', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/LiveController.php');

    expect($controller)
        ->toContain("LiveCacheService::remember('operations-monitor'")
        ->toContain("'drivers'  => \$drivers->map")
        ->toContain("'vehicles' => \$vehicles->map")
        ->toContain("'fleets'   => \$this->buildOperationsMonitorFleetTree")
        ->toContain("'driver_ids'            => \$driverIds->all()")
        ->toContain("'vehicle_ids'           => \$vehicleIds->all()")
        ->toContain("'meta'     => [")
        ->not->toContain("'drivers'               => \$this->whenLoaded('drivers'")
        ->not->toContain("'vehicles'              => \$this->whenLoaded('vehicles'");
});

test('operations monitor cache is invalidated by live cache and resource mutations', function () {
    $cache           = file_get_contents(__DIR__ . '/../src/Support/LiveCacheService.php');
    $driverObserver  = file_get_contents(__DIR__ . '/../src/Observers/DriverObserver.php');
    $vehicleObserver = file_get_contents(__DIR__ . '/../src/Observers/VehicleObserver.php');
    $fleetObserver   = file_get_contents(__DIR__ . '/../src/Observers/FleetObserver.php');
    $fleetController = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/FleetController.php');

    expect($cache)->toContain("'operations-monitor'");
    expect($driverObserver)->toContain("LiveCacheService::invalidateMultiple(['drivers', 'operations-monitor'])");
    expect($vehicleObserver)->toContain("LiveCacheService::invalidateMultiple(['vehicles', 'operations-monitor'])");
    expect($fleetObserver)->toContain("LiveCacheService::invalidate('operations-monitor')");
    expect(substr_count($fleetController, "LiveCacheService::invalidate('operations-monitor')"))->toBe(4);
});
