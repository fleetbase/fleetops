<?php

test('radar routes are registered beside the hubs', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)
        ->toContain("['prefix' => 'radar']")
        ->toContain("\$router->get('items', 'RadarController@items');")
        ->toContain("\$router->get('summary', 'RadarController@summary');")
        ->toContain("\$router->post('items/bulk', 'RadarController@bulk');")
        ->toContain("\$router->post('items/{key}/acknowledge', 'RadarController@acknowledge');")
        ->toContain("\$router->post('items/{key}/snooze', 'RadarController@snooze');")
        ->toContain("\$router->post('items/{key}/wake', 'RadarController@wake');")
        ->toContain("\$router->post('items/{key}/assign', 'RadarController@assign');")
        ->toContain("\$router->post('items/{key}/plan', 'RadarController@plan');")
        ->toContain("\$router->post('items/{key}/resolve', 'RadarController@resolve');")
        ->toContain("\$router->post('notices', 'RadarController@storeNotice');")
        ->toContain("\$router->delete('notices/{id}', 'RadarController@destroyNotice');");

    // The bulk route is declared before the keyed routes so `items/bulk`
    // is never read as a key called "bulk".
    expect(strpos($routes, 'items/bulk'))->toBeLessThan(strpos($routes, 'items/{key}/acknowledge'));
});

test('radar controller exposes every routed action', function () {
    $controller = new ReflectionClass(Fleetbase\FleetOps\Http\Controllers\Internal\v1\RadarController::class);

    foreach (['items', 'summary', 'bulk', 'acknowledge', 'snooze', 'wake', 'assign', 'plan', 'resolve', 'storeNotice', 'destroyNotice'] as $method) {
        expect($controller->hasMethod($method))->toBeTrue("RadarController@{$method} is routed but missing");
    }
});
