<?php

use Illuminate\Http\Request;

/**
 * Covers the authorize() gate shared by the public API form requests: every
 * one admits a request carrying an api credential, and the sanctum-aware
 * variants also admit a sanctum token session.
 */
function fleetopsRequestWithSession(array $sessionKeys): Request
{
    $request = Request::create('/v1/resource', 'POST');
    $store   = app('session.store');

    foreach (['api_credential', 'is_sanctum_token'] as $key) {
        $store->forget($key);
    }
    foreach ($sessionKeys as $key => $value) {
        $store->put($key, $value);
    }

    $request->setLaravelSession($store);
    app()->instance('request', $request);

    return $request;
}

test('api form requests authorize credentialed sessions', function (string $requestClass, bool $acceptsSanctum) {
    // An api credential always authorizes
    fleetopsRequestWithSession(['api_credential' => 'credential-uuid']);
    expect((new $requestClass())->authorize())->toBeTrue();

    // A bare session never does
    fleetopsRequestWithSession([]);
    expect((new $requestClass())->authorize())->toBeFalse();

    // Sanctum token sessions are accepted only by the sanctum-aware requests
    fleetopsRequestWithSession(['is_sanctum_token' => true]);
    expect((new $requestClass())->authorize())->toBe($acceptsSanctum);
})->with([
    'device'            => [Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest::class, true],
    'fuel transaction'  => [Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest::class, true],
    'place'             => [Fleetbase\FleetOps\Http\Requests\CreatePlaceRequest::class, true],
    'sensor'            => [Fleetbase\FleetOps\Http\Requests\CreateSensorRequest::class, true],
    'vehicle'           => [Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest::class, true],
    'work order'        => [Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest::class, true],
    'order'             => [Fleetbase\FleetOps\Http\Requests\CreateOrderRequest::class, false],
    'service rate'      => [Fleetbase\FleetOps\Http\Requests\CreateServiceRateRequest::class, false],
    'driver simulation' => [Fleetbase\FleetOps\Http\Requests\DriverSimulationRequest::class, false],
]);
