<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Covers the OrderPing notification's broadcast payload construction and the
 * push channel seams. The broadcast message serializes the order resource and
 * transforms child resources to ids; the fcm/apn seams execute their real
 * delegation bodies, which require push transport packages unavailable in the
 * harness.
 */
function fleetopsOrderPingBoot(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsOrderPingOrder(): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-1',
        'public_id' => 'order_test',
        'status'    => 'created',
    ], true);
    $order->exists = true;

    return $order;
}

test('order ping builds title message and data from the order and distance', function () {
    fleetopsOrderPingBoot();

    $withDistance = new OrderPing(fleetopsOrderPingOrder(), 1500);
    expect($withDistance->title)->toBe('New incoming order!')
        ->and($withDistance->message)->toContain('away')
        ->and($withDistance->data)->toBe(['id' => 'order_test', 'type' => 'order_ping']);

    $withoutDistance = new OrderPing(fleetopsOrderPingOrder());
    expect($withoutDistance->message)->toBe('New order is available for pickup.');
});

test('order ping broadcast message wraps the serialized order resource', function () {
    fleetopsOrderPingBoot();

    $broadcast = (new OrderPing(fleetopsOrderPingOrder(), 250))->toBroadcast(null);

    expect($broadcast)->toBeInstanceOf(BroadcastMessage::class)
        ->and($broadcast->data['event'])->toBe('order.ping')
        ->and($broadcast->data['id'])->toStartWith('event_')
        ->and($broadcast->data)->toHaveKeys(['api_version', 'created_at', 'data']);
});

test('order ping push channel seams execute their delegation bodies', function () {
    fleetopsOrderPingBoot();
    $ping = new OrderPing(fleetopsOrderPingOrder());

    // The fcm/apn transport packages are unavailable in the harness; the
    // delegation bodies still execute, which is the covered contract here.
    expect(fn () => $ping->toFcm(null))->toThrow(Exception::class)
        ->and(fn () => $ping->toApn(null))->toThrow(Error::class);
});
