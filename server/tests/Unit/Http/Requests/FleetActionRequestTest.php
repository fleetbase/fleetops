<?php

use Fleetbase\FleetOps\Http\Requests\Internal\FleetActionRequest;

class FleetOpsFleetActionRequestProbe extends FleetActionRequest
{
    public string $action     = 'update';
    public array $permissions = [];
    public array $checks      = [];

    protected function actionMethod(): string
    {
        return $this->action;
    }

    protected function can(string $permission): bool
    {
        $this->checks[] = $permission;

        return $this->permissions[$permission] ?? false;
    }
}

test('fleet action request maps controller actions to fleet permissions', function (string $action, string $permission) {
    $request              = new FleetOpsFleetActionRequestProbe();
    $request->action      = $action;
    $request->permissions = [$permission => true];

    expect($request->authorize())->toBeTrue()
        ->and($request->checks)->toBe([$permission]);
})->with([
    'assign vehicle'  => ['assignVehicle', 'fleet-ops assign-vehicle-for fleet'],
    'assign driver'   => ['assignDriver', 'fleet-ops assign-driver-for fleet'],
    'remove vehicle'  => ['removeVehicle', 'fleet-ops remove-vehicle-for fleet'],
    'remove driver'   => ['removeDriver', 'fleet-ops remove-driver-for fleet'],
    'fallback update' => ['updateRecord', 'fleet-ops update fleet'],
]);

test('fleet action request denies actions when mapped permissions are unavailable', function (string $action, string $permission) {
    $request         = new FleetOpsFleetActionRequestProbe();
    $request->action = $action;

    expect($request->authorize())->toBeFalse()
        ->and($request->checks)->toBe([$permission]);
})->with([
    'assign vehicle denied'  => ['assignVehicle', 'fleet-ops assign-vehicle-for fleet'],
    'assign driver denied'   => ['assignDriver', 'fleet-ops assign-driver-for fleet'],
    'remove vehicle denied'  => ['removeVehicle', 'fleet-ops remove-vehicle-for fleet'],
    'remove driver denied'   => ['removeDriver', 'fleet-ops remove-driver-for fleet'],
    'fallback update denied' => ['archiveRecord', 'fleet-ops update fleet'],
]);

test('fleet action request exposes assignment validation rules', function () {
    expect((new FleetActionRequest())->rules())->toBe([
        'fleet'   => 'string|exists:fleets,uuid',
        'driver'  => 'nullable|string|exists:drivers,uuid',
        'vehicle' => 'nullable|string|exists:vehicles,uuid',
    ]);
});

test('the real action method seam reads the resolved route action', function () {
    // The probe above overrides actionMethod(); this exercises the real body
    $request = FleetActionRequest::create('/int/v1/fleets/assign-vehicle', 'POST');
    $request->setRouteResolver(fn () => new class {
        public function getActionMethod()
        {
            return 'assignVehicle';
        }

        public function getAction($key = null)
        {
            return $key === null ? ['controller' => 'FleetController@assignVehicle'] : 'FleetController@assignVehicle';
        }
    });

    $reflection = new ReflectionMethod(FleetActionRequest::class, 'actionMethod');
    $reflection->setAccessible(true);

    expect($reflection->invoke($request))->toBe('assignVehicle');
});
