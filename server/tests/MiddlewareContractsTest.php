<?php

use Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware;
use Illuminate\Http\Request;

test('transform location middleware normalizes nested null locations', function () {
    $request = Request::create('/v1/orders', 'POST', [
        'location' => null,
        'payload'  => [
            'pickup'  => ['location' => null],
            'dropoff' => ['location' => [103.8, 1.3]],
            'meta'    => ['location' => null],
        ],
        'entities' => [
            ['name' => 'Box', 'location' => null],
            ['name' => 'Crate', 'location' => [104.1, 1.4]],
        ],
        'notes' => 'leave unchanged',
    ]);

    $response = (new TransformLocationMiddleware())->handle($request, function (Request $request) {
        return $request->all();
    });

    expect($response)->toMatchArray([
        'location' => [0, 0],
        'payload'  => [
            'pickup'  => ['location' => [0, 0]],
            'dropoff' => ['location' => [103.8, 1.3]],
            'meta'    => ['location' => [0, 0]],
        ],
        'entities' => [
            ['name' => 'Box', 'location' => [0, 0]],
            ['name' => 'Crate', 'location' => [104.1, 1.4]],
        ],
        'notes' => 'leave unchanged',
    ]);
});
