<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Route;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;

function fleetopsRouteModelUseInMemoryConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('route model exposes order relation and delegated order accessors', function () {
    fleetopsRouteModelUseInMemoryConnection();

    $order = new Order();
    $order->setRawAttributes([
        'public_id'     => 'order_public',
        'internal_id'   => 'order-internal',
        'status'        => 'dispatched',
        'dispatched_at' => '2026-08-03 11:22:33',
    ], true);
    $order->setRelation('payload', (object) ['uuid' => 'payload-uuid']);
    $order->setRelation('driverAssigned', (object) ['uuid' => 'driver-uuid']);

    $route = new Route();
    $route->setRelation('order', $order);

    expect($route->order())->toBeInstanceOf(BelongsTo::class)
        ->and($route->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($route->payload)->toBe($order->payload)
        ->and($route->driver)->toBe($order->driverAssigned)
        ->and($route->order_status)->toBe('dispatched')
        ->and($route->order_public_id)->toBe('order_public')
        ->and($route->order_internal_id)->toBe('order-internal')
        ->and($route->order_dispatched_at->toDateTimeString())->toBe('2026-08-03 11:22:33')
        ->and((new Route())->payload)->toBeNull();
});
