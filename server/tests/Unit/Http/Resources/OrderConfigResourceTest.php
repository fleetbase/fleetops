<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Http\Resources\v1\OrderConfig as OrderConfigResource;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Http\Request;

class FleetOpsOrderConfigResourceRequestState
{
    public static ?Request $request = null;
}

if (!class_exists('FleetOpsSupportRequestState')) {
    class FleetOpsSupportRequestState
    {
        public static ?Request $request = null;
    }
}

if (!function_exists('Fleetbase\Support\request')) {
    eval('namespace Fleetbase\Support; function request($key = null, $default = null) { return \FleetOpsSupportRequestState::$request; }');
}

test('order config resource projects empty and invalid flow as an empty list', function () {
    $request                                          = Request::create('/v1/fleet-ops/order-configs/order_config_public', 'GET');
    FleetOpsOrderConfigResourceRequestState::$request = $request;
    FleetOpsSupportRequestState::$request             = $request;

    $config = new OrderConfig();
    $config->setRawAttributes([
        'id'          => 7,
        'uuid'        => 'order-config-uuid',
        'public_id'   => 'order_config_public',
        'key'         => 'transport',
        'name'        => 'Transport',
        'description' => 'Default transport flow.',
        'flow'        => null,
    ], true);

    $payload = (new OrderConfigResource($config))->resolve($request);

    expect($payload['id'])->toBe('order_config_public')
        ->and($payload['flow'])->toBe([]);
});
