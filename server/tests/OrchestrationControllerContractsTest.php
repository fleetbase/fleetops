<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController;
use Fleetbase\FleetOps\Orchestration\Engines\GreedyOrchestrationEngine;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Illuminate\Http\Request;

function fleetopsOrchestrationController(): OrchestrationController
{
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new GreedyOrchestrationEngine());

    return new OrchestrationController($registry);
}

function callOrchestrationControllerHelper(OrchestrationController $controller, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(OrchestrationController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

test('orchestration controller exposes engines and rejects empty commits before persistence', function () {
    $controller = fleetopsOrchestrationController();

    $engines = $controller->engines();
    $commit  = $controller->commit(Request::create('/orchestrator/commit', 'POST', [
        'assignments' => [],
    ]));

    expect($engines->getData(true))->toBe([
        'engines' => [
            [
                'id'    => 'greedy',
                'name'  => 'Greedy (built-in)',
            ],
        ],
    ])
        ->and($commit->getStatusCode())->toBe(422)
        ->and($commit->getData(true))->toBe(['error' => 'No assignments provided.']);
});

test('orchestration import helpers build place points and entity payloads from rows', function () {
    $controller = fleetopsOrchestrationController();

    $place = callOrchestrationControllerHelper($controller, 'buildPlaceData', [
        'pickup_name'        => 'Warehouse',
        'pickup_street1'     => '1 Depot Way',
        'pickup_street2'     => 'Dock 4',
        'pickup_city'        => 'Singapore',
        'pickup_state'       => 'SG',
        'pickup_postal_code' => '018956',
        'pickup_country'     => 'SG',
        'pickup_phone'       => '+6512345678',
        'pickup_lat'         => '1.3001',
        'pickup_lng'         => '103.8002',
    ], 'pickup');

    $emptyPoint = callOrchestrationControllerHelper($controller, 'buildLocationPoint', '', '103.8');
    $zeroPoint  = callOrchestrationControllerHelper($controller, 'buildLocationPoint', '0', '0');
    $point      = callOrchestrationControllerHelper($controller, 'buildLocationPoint', '1.5', '103.9');
    $empty      = callOrchestrationControllerHelper($controller, 'buildEntityData', [], 'company-uuid');
    $entity     = callOrchestrationControllerHelper($controller, 'buildEntityData', [
        'entity_name'            => 'Parcel',
        'entity_type'            => 'box',
        'entity_description'     => 'Fragile parts',
        'entity_sku'             => 'SKU-1',
        'entity_barcode'         => 'BAR-1',
        'entity_internal_id'     => 'INT-1',
        'entity_declared_value'  => '12.34',
        'entity_currency'        => 'SGD',
        'entity_price'           => '15.5',
        'entity_sale_price'      => '14.5',
        'entity_weight'          => '2.75',
        'entity_weight_unit'     => 'kg',
        'entity_length'          => '10',
        'entity_width'           => '20',
        'entity_height'          => '30',
        'entity_dimensions_unit' => 'cm',
    ], 'company-uuid');

    expect($place)->toBe([
        'name'        => 'Warehouse',
        'street1'     => '1 Depot Way',
        'street2'     => 'Dock 4',
        'city'        => 'Singapore',
        'province'    => 'SG',
        'postal_code' => '018956',
        'country'     => 'SG',
        'phone'       => '+6512345678',
        'location'    => 'POINT(103.8002 1.3001)',
    ])
        ->and($emptyPoint)->toBeNull()
        ->and($zeroPoint)->toBeNull()
        ->and($point)->toBe('POINT(103.9 1.5)')
        ->and($empty)->toBeNull()
        ->and($entity)->toMatchArray([
            'company_uuid'    => 'company-uuid',
            'name'            => 'Parcel',
            'type'            => 'box',
            'description'     => 'Fragile parts',
            'sku'             => 'SKU-1',
            'barcode'         => 'BAR-1',
            'internal_id'     => 'INT-1',
            'declared_value'  => 12.34,
            'currency'        => 'SGD',
            'price'           => 15.5,
            'sale_price'      => 14.5,
            'weight'          => 2.75,
            'weight_unit'     => 'kg',
            'length'          => 10.0,
            'width'           => 20.0,
            'height'          => 30.0,
            'dimensions_unit' => 'cm',
        ]);
});
