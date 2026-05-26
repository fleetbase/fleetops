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
