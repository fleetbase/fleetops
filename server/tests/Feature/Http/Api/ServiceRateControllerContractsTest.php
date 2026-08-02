<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceRateController;
use Fleetbase\FleetOps\Http\Requests\CreateServiceRateRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateServiceRateRequest;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\ServiceRateFee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiServiceRateControllerProbe extends ServiceRateController
{
    public ?FleetOpsApiServiceRateFake $createdServiceRate = null;
    public array $createdRates                             = [];
    public array $createdFees                              = [];
    public array $models                                   = [];
    public array $resolvedUuids                            = [];
    public array $resources                                = [];
    public array $deletedResources                         = [];
    public array $collections                              = [];

    protected function resolveUuid(string $table, array $where): ?string
    {
        $this->resolvedUuids[] = [$table, $where];

        return $where['public_id'] . '-uuid';
    }

    protected function createServiceRate(array $input): ServiceRate
    {
        $this->createdRates[] = $input;

        return $this->createdServiceRate;
    }

    protected function createServiceRateFee(array $input): ServiceRateFee
    {
        $this->createdFees[] = $input;

        $fee = new ServiceRateFee();
        $fee->setRawAttributes(array_merge(['uuid' => 'fee-' . count($this->createdFees)], $input));

        return $fee;
    }

    protected function findServiceRate(string $id): ServiceRate
    {
        if (!array_key_exists($id, $this->models)) {
            throw (new ModelNotFoundException())->setModel(ServiceRate::class, $id);
        }

        return $this->models[$id];
    }

    protected function queryServiceRates(Request $request): mixed
    {
        return [
            ['uuid' => 'rate-a', 'currency' => 'USD'],
            ['uuid' => 'rate-b', 'currency' => 'USD'],
        ];
    }

    protected function serviceRateResource(ServiceRate $serviceRate): mixed
    {
        $this->resources[] = $serviceRate->uuid;

        return [
            'uuid'                    => $serviceRate->uuid,
            'public_id'               => $serviceRate->public_id,
            'rate_calculation_method' => $serviceRate->rate_calculation_method,
        ];
    }

    protected function serviceRateResourceCollection($serviceRates): mixed
    {
        $this->collections[] = $serviceRates;

        return ['collection' => $serviceRates];
    }

    protected function deletedServiceRateResource(ServiceRate $serviceRate): mixed
    {
        $this->deletedResources[] = $serviceRate->uuid;

        return ['deleted' => $serviceRate->uuid];
    }
}

class FleetOpsApiServiceRateFake extends ServiceRate
{
    public array $visibleForTest = [];
    public array $updates        = [];
    public bool $deleted         = false;

    public function makeVisible($attributes)
    {
        $this->visibleForTest[] = $attributes;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }
}

function fleetopsApiServiceRateController(): FleetOpsApiServiceRateControllerProbe
{
    return new FleetOpsApiServiceRateControllerProbe();
}

function fleetopsApiServiceRateFake(string $uuid = 'service-rate-uuid', string $publicId = 'service_rate_public', string $method = 'fixed_meter'): FleetOpsApiServiceRateFake
{
    $serviceRate = new FleetOpsApiServiceRateFake();
    $serviceRate->setRawAttributes([
        'uuid'                    => $uuid,
        'public_id'               => $publicId,
        'rate_calculation_method' => $method,
        'currency'                => 'USD',
    ]);

    return $serviceRate;
}

function fleetopsApiServiceRateRequest(string $class, array $input): Request
{
    return $class::create('/api/v1/service-rates', 'POST', $input);
}

test('api service rate controller creates fixed rates with area zone and meter fees', function () {
    session(['company' => 'company-uuid']);

    $controller                     = fleetopsApiServiceRateController();
    $controller->createdServiceRate = fleetopsApiServiceRateFake();

    $response = $controller->create(fleetopsApiServiceRateRequest(CreateServiceRateRequest::class, [
        'service_name'            => 'Same Day',
        'service_type'            => 'delivery',
        'service_area'            => 'area_public',
        'zone'                    => 'zone_public',
        'rate_calculation_method' => 'fixed_meter',
        'currency'                => 'USD',
        'base_fee'                => 10,
        'per_meter_unit'          => 'km',
        'meter_fees'              => [
            ['distance' => 5, 'fee' => 15],
            ['distance' => 10, 'fee' => 25],
        ],
        'ignored' => 'not copied',
    ]));

    expect($response['uuid'])->toBe('service-rate-uuid')
        ->and($controller->createdRates)->toHaveCount(1)
        ->and($controller->createdRates[0])->toMatchArray([
            'company_uuid'             => 'company-uuid',
            'service_name'             => 'Same Day',
            'service_type'             => 'delivery',
            'service_area_uuid'        => 'area_public-uuid',
            'zone_uuid'                => 'zone_public-uuid',
            'rate_calculation_method'  => 'fixed_meter',
            'currency'                 => 'USD',
            'base_fee'                 => 10,
            'per_meter_unit'           => 'km',
        ])
        ->and(array_slice($controller->createdRates[0]['meter_fees'], 0, 2))->toBe([
            ['distance' => 5, 'fee' => 15],
            ['distance' => 10, 'fee' => 25],
        ])
        ->and($controller->resolvedUuids)->toBe([
            ['service_areas', ['public_id' => 'area_public', 'company_uuid' => 'company-uuid']],
            ['zones', ['public_id' => 'zone_public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($controller->createdFees)->toBe([
            [
                'service_rate_uuid' => 'service-rate-uuid',
                'distance'          => 5,
                'distance_unit'     => 'km',
                'fee'               => 15,
                'currency'          => 'USD',
            ],
            [
                'service_rate_uuid' => 'service-rate-uuid',
                'distance'          => 10,
                'distance_unit'     => 'km',
                'fee'               => 25,
                'currency'          => 'USD',
            ],
        ])
        ->and($controller->createdServiceRate->visibleForTest)->toBe(['meter_fees']);
});

test('api service rate controller skips meter fee creation for non fixed rates', function () {
    session(['company' => 'company-uuid']);

    $controller                     = fleetopsApiServiceRateController();
    $controller->createdServiceRate = fleetopsApiServiceRateFake(method: 'per_meter');

    $controller->create(fleetopsApiServiceRateRequest(CreateServiceRateRequest::class, [
        'service_name'            => 'Per Meter',
        'service_type'            => 'delivery',
        'rate_calculation_method' => 'per_meter',
        'currency'                => 'USD',
        'meter_fees'              => [
            ['distance' => 5, 'fee' => 15],
        ],
    ]));

    expect($controller->createdFees)->toBe([])
        ->and($controller->createdServiceRate->visibleForTest)->toBe([]);
});

test('api service rate controller updates finds deletes and queries rates', function () {
    session(['company' => 'company-uuid']);

    $controller                 = fleetopsApiServiceRateController();
    $serviceRate                = fleetopsApiServiceRateFake();
    $controller->models['rate'] = $serviceRate;

    $updated = $controller->update('rate', fleetopsApiServiceRateRequest(UpdateServiceRateRequest::class, [
        'service_name' => 'Updated service',
        'service_area' => 'area_public',
        'zone'         => 'zone_public',
        'currency'     => 'CAD',
    ]));

    expect($updated['uuid'])->toBe('service-rate-uuid')
        ->and($serviceRate->updates)->toBe([[
            'service_name'      => 'Updated service',
            'currency'          => 'CAD',
            'service_area_uuid' => 'area_public-uuid',
            'zone_uuid'         => 'zone_public-uuid',
        ]])
        ->and($controller->find('rate')['uuid'])->toBe('service-rate-uuid')
        ->and($controller->delete('rate'))->toBe(['deleted' => 'service-rate-uuid'])
        ->and($serviceRate->deleted)->toBeTrue()
        ->and($controller->query(Request::create('/api/v1/service-rates', 'GET')))->toBe([
            'collection' => [
                ['uuid' => 'rate-a', 'currency' => 'USD'],
                ['uuid' => 'rate-b', 'currency' => 'USD'],
            ],
        ]);
});

test('api service rate controller reports missing resources', function () {
    $controller = fleetopsApiServiceRateController();

    expect($controller->find('missing')->getStatusCode())->toBe(404)
        ->and($controller->update('missing', fleetopsApiServiceRateRequest(UpdateServiceRateRequest::class, [
            'service_name' => 'Missing',
        ]))->getData(true))->toBe(['error' => 'ServiceRate resource not found.'])
        ->and($controller->delete('missing')->getStatusCode())->toBe(404);
});
