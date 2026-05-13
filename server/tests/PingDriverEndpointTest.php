<?php

test('ping driver is only exposed through the internal fleetops order route group', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)->not->toContain("\$router->post('{id}/ping-driver', 'OrderController@pingDriver');")
        ->and($routes)->toContain("\$router->post('{id}/ping-driver', \$controller('pingDriver'));");
});

test('ping driver handler belongs to the internal order controller', function () {
    $apiController      = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/OrderController.php');
    $internalController = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/OrderController.php');

    expect($apiController)->not->toContain('function pingDriver')
        ->and($apiController)->not->toContain('Notifications\OrderPing')
        ->and($internalController)->toContain('function pingDriver')
        ->and($internalController)->toContain('Notifications\OrderPing')
        ->and($internalController)->toContain("fleet-ops update order");
});
