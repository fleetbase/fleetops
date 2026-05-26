<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderConfigController;
use Fleetbase\FleetOps\Http\Resources\Internal\v1\OrderConfig as InternalOrderConfigResource;
use Fleetbase\FleetOps\Http\Resources\v1\OrderConfig as OrderConfigResource;

/*
|--------------------------------------------------------------------------
| OrderConfig API surface — static shape checks.
|
| The OrderConfig public API surface gives consumers (customer portals,
| third-party integrations) a read-only projection of the OrderConfig
| `flow` JSON so they can drive status filters, activity labels, and
| similar UI from the canonical config rather than hardcoding their own.
|
| These mirror the lightweight static checks used elsewhere in this
| package and verify the routes, controller methods, and resource shape
| exist without booting Laravel.
|--------------------------------------------------------------------------
*/

test('order-configs route group is registered in the consumable v1 namespace', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)
        ->toContain("\$router->group(['prefix' => 'order-configs']")
        ->and($routes)->toContain("OrderConfigController@query")
        ->and($routes)->toContain("OrderConfigController@find");
});

test('OrderConfigController exposes only read-only methods', function () {
    foreach (['query', 'find'] as $method) {
        expect(method_exists(OrderConfigController::class, $method))->toBeTrue(
            "OrderConfigController::{$method} missing",
        );
    }

    // No write methods are exposed — OrderConfigs are owned by operator UIs.
    foreach (['create', 'update', 'delete', 'store', 'destroy'] as $method) {
        expect(method_exists(OrderConfigController::class, $method))->toBeFalse(
            "OrderConfigController::{$method} should NOT exist on the public surface",
        );
    }
});

test('find() resolves by uuid, public_id, namespace, or key', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/OrderConfigController.php');

    // The controller defers to the canonical resolver so /transport,
    // /system:order-config:transport, the public_id, and the uuid all work.
    expect($source)
        ->toContain('OrderConfig::resolveFromIdentifier')
        ->toContain('findRecordOrFail');
});

test('OrderConfig resource projects only public-safe fields', function () {
    expect(class_exists(OrderConfigResource::class))->toBeTrue();

    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/OrderConfig.php');

    expect($source)
        // Public contract: id/key/name/namespace + the flow projection.
        ->toContain("'key'")
        ->toContain("'name'")
        ->toContain("'namespace'")
        ->toContain("'flow'")
        ->toContain('projectFlow')
        // Each flow row keeps the canonical activity keys.
        ->toContain("'code'")
        ->toContain("'status'")
        ->toContain("'complete'")
        // Internal config payload must not be exposed on the public surface.
        ->not->toContain("'entities'");
});

test('internal OrderConfig resource preserves editable flow graph fields', function () {
    expect(class_exists(InternalOrderConfigResource::class))->toBeTrue();

    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/Internal/v1/OrderConfig.php');

    expect($source)
        ->toContain("'flow'")
        ->toContain('$this->flow')
        ->toContain("'entities'")
        ->toContain("'core_service'");
});
