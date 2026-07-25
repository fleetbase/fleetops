<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

function fleetopsSearchControllerMethod(string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod(SearchController::class, $method);
    $reflection->setAccessible(true);

    return $reflection;
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
