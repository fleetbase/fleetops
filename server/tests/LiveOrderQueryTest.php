<?php

use Fleetbase\FleetOps\Support\LiveOrderQuery;

test('active live order query uses the same active status rules as the map overlay', function () {
    $query = LiveOrderQuery::make('company_test', [
        'active'            => true,
        'apply_permissions' => false,
    ]);

    $bindings = $query->getBindings();

    expect($bindings)->toContain('company_test')
        ->and($bindings)->toContain('created')
        ->and($bindings)->toContain('pending')
        ->and($bindings)->toContain('completed')
        ->and($bindings)->toContain('canceled')
        ->and($bindings)->toContain('expired')
        ->and($bindings)->toContain('order_canceled');
});

test('live order query requires renderable payload and tracking data', function () {
    $query = LiveOrderQuery::make('company_test', [
        'active'            => true,
        'apply_permissions' => false,
    ]);

    $sql = $query->toSql();

    expect($sql)->toContain('exists')
        ->and($sql)->toContain('payload')
        ->and($sql)->toContain('tracking')
        ->and($sql)->toContain('driver');
});
