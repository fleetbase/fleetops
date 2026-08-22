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

test('order config resource publishes the flow graph so consumers can sequence it', function () {
    $request                                          = Request::create('/v1/fleet-ops/order-configs/order_config_public', 'GET');
    FleetOpsOrderConfigResourceRequestState::$request = $request;
    FleetOpsSupportRequestState::$request             = $request;

    // Declared deliberately out of workflow order, which is how flows are
    // stored: `completed` before the step that leads to it, `dispatched` last.
    $config = new OrderConfig();
    $config->setRawAttributes([
        'id'        => 8,
        'uuid'      => 'order-config-uuid',
        'public_id' => 'order_config_public',
        'key'       => 'transport',
        'name'      => 'Transport',
        'flow'      => [
            ['code' => 'completed', 'status' => 'Order Completed', 'complete' => true, 'activities' => []],
            ['code' => 'created', 'status' => 'Order Created', 'activities' => ['dispatched']],
            ['code' => 'dispatched', 'status' => 'Order Dispatched', 'activities' => ['enroute'], 'sequence' => 2],
            [
                'code'       => 'enroute',
                'status'     => 'Driver Enroute',
                'activities' => ['completed', 'failed'],
                'logic'      => [['type' => 'and', 'conditions' => []]],
            ],
        ],
    ], true);

    $flow = (new OrderConfigResource($config))->resolve($request)['flow'];
    $byCode = collect($flow)->keyBy('code');

    expect($byCode['created']['activities'])->toBe(['dispatched'])
        ->and($byCode['dispatched']['sequence'])->toBe(2)
        ->and($byCode['enroute']['activities'])->toBe(['completed', 'failed'])
        ->and($byCode['enroute']['logic'])->toBe([['type' => 'and', 'conditions' => []]])
        ->and($byCode['completed']['complete'])->toBeTrue();
});

test('order config resource normalises transitions written as objects', function () {
    $request                                          = Request::create('/v1/fleet-ops/order-configs/order_config_public', 'GET');
    FleetOpsOrderConfigResourceRequestState::$request = $request;
    FleetOpsSupportRequestState::$request             = $request;

    // Flows have been authored both ways; a consumer should not have to guess.
    $config = new OrderConfig();
    $config->setRawAttributes([
        'id'        => 9,
        'uuid'      => 'order-config-uuid',
        'public_id' => 'order_config_public',
        'key'       => 'transport',
        'name'      => 'Transport',
        'flow'      => [
            ['code' => 'created', 'activities' => [['code' => 'dispatched'], 'enroute', ['label' => 'no code here']]],
        ],
    ], true);

    $flow = (new OrderConfigResource($config))->resolve($request)['flow'];

    expect($flow[0]['activities'])->toBe(['dispatched', 'enroute']);
});

test('order config resource keeps flow fields absent from a legacy activity null rather than missing', function () {
    $request                                          = Request::create('/v1/fleet-ops/order-configs/order_config_public', 'GET');
    FleetOpsOrderConfigResourceRequestState::$request = $request;
    FleetOpsSupportRequestState::$request             = $request;

    $config = new OrderConfig();
    $config->setRawAttributes([
        'id'        => 10,
        'uuid'      => 'order-config-uuid',
        'public_id' => 'order_config_public',
        'key'       => 'transport',
        'name'      => 'Transport',
        'flow'      => [['code' => 'created', 'status' => 'Order Created']],
    ], true);

    $flow = (new OrderConfigResource($config))->resolve($request)['flow'];

    // Present-and-null is a contract a client can read; absent is one it has to
    // feel for.
    expect($flow[0])->toHaveKeys(['sequence', 'activities', 'logic'])
        ->and($flow[0]['sequence'])->toBeNull()
        ->and($flow[0]['activities'])->toBe([])
        ->and($flow[0]['logic'])->toBeNull();
});
