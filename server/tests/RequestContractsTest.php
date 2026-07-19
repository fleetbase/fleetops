<?php

use Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest;
use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
use Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest;
use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
use Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDeviceRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateWorkOrderRequest;
use Fleetbase\FleetOps\Rules\ResolvablePoint;

function requestRules(string $class, string $method = 'POST'): array
{
    return $class::create('/fleetops-test', $method)->rules();
}

function ruleStrings(array $rules): array
{
    return array_map(fn ($rule) => (string) $rule, $rules);
}

test('device requests require names on create and protect paired location fields', function () {
    $createRules = requestRules(CreateDeviceRequest::class);
    $updateRules = requestRules(UpdateDeviceRequest::class, 'PATCH');

    expect(ruleStrings($createRules['name']))->toContain('required', 'string')
        ->and(ruleStrings($updateRules['name']))->not->toContain('required')
        ->and($createRules['last_position'][1])->toBeInstanceOf(ResolvablePoint::class)
        ->and($createRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
        ->and($createRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
        ->and($createRules['attachable'])->toBe(['nullable', 'required_with:attachable_type', 'string'])
        ->and($createRules['meta'])->toBe(['nullable', 'array'])
        ->and($createRules['options'])->toBe(['nullable', 'array']);
});

test('work order requests require subjects on create and preserve cost metadata rules', function () {
    $createRules = requestRules(CreateWorkOrderRequest::class);
    $updateRules = requestRules(UpdateWorkOrderRequest::class, 'PATCH');

    expect(ruleStrings($createRules['subject']))->toContain('required', 'string')
        ->and(ruleStrings($updateRules['subject']))->not->toContain('required')
        ->and($createRules['target'])->toBe(['nullable', 'required_with:target_type', 'string'])
        ->and($createRules['assignee'])->toBe(['nullable', 'required_with:assignee_type', 'string'])
        ->and($createRules['currency'])->toBe(['nullable', 'string', 'size:3'])
        ->and($createRules['checklist'])->toBe(['nullable', 'array'])
        ->and($createRules['cost_breakdown'])->toBe(['nullable', 'array'])
        ->and($createRules['meta'])->toBe(['nullable', 'array']);
});

test('fuel transaction request requires provider identifiers on create', function () {
    $createRules = requestRules(CreateFuelTransactionRequest::class);
    $patchRules  = requestRules(CreateFuelTransactionRequest::class, 'PATCH');

    expect(ruleStrings($createRules['provider']))->toContain('required', 'string')
        ->and(ruleStrings($createRules['provider_transaction_id']))->toContain('required', 'string')
        ->and(ruleStrings($patchRules['provider']))->not->toContain('required')
        ->and(ruleStrings($patchRules['provider_transaction_id']))->not->toContain('required')
        ->and($createRules['station_latitude'])->toBe(['nullable', 'numeric'])
        ->and($createRules['station_longitude'])->toBe(['nullable', 'numeric'])
        ->and($createRules['normalized_payload'])->toBe(['nullable', 'array'])
        ->and($createRules['raw_payload'])->toBe(['nullable', 'array'])
        ->and($createRules['meta'])->toBe(['nullable', 'array']);
});

test('vehicle and fuel report requests expose core validation contracts', function () {
    $vehicleRules          = requestRules(CreateVehicleRequest::class);
    $fuelReportRules       = requestRules(CreateFuelReportRequest::class);
    $fuelReportUpdateRules = requestRules(UpdateFuelReportRequest::class, 'PATCH');

    expect($vehicleRules['location'][1])->toBeInstanceOf(ResolvablePoint::class)
        ->and($vehicleRules['latitude'])->toBe(['nullable', 'required_with:longitude'])
        ->and($vehicleRules['longitude'])->toBe(['nullable', 'required_with:latitude'])
        ->and(ruleStrings($vehicleRules['status']))->toContain('nullable')
        ->and(implode('|', ruleStrings($vehicleRules['status'])))->toContain('operational')
        ->and($fuelReportRules['driver'])->toBe(['required'])
        ->and($fuelReportRules['odometer'])->toBe(['required'])
        ->and($fuelReportRules['volume'])->toBe(['required'])
        ->and($fuelReportUpdateRules['driver'])->toBe(['required']);
});
