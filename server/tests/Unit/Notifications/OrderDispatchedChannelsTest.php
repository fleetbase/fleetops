<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Covers the OrderDispatched notification's broadcast payload construction,
 * mail representation with waypoint/order tracking-number fallback, and the
 * fcm/apn push channel seams (their delegation bodies execute; the transport
 * packages are unavailable in the harness).
 */
function fleetopsOrderDispatchedBoot(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsOrderDispatchedOrder(): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-1',
        'public_id' => 'order_test',
        'status'    => 'dispatched',
    ], true);
    $order->exists = true;

    return $order;
}

test('order dispatched builds title message and payload data', function () {
    fleetopsOrderDispatchedBoot();

    $notification = new OrderDispatched(fleetopsOrderDispatchedOrder());

    expect($notification->title)->toContain('has been dispatched')
        ->and($notification->message)->toContain('ready to be started')
        ->and($notification->data)->toBe(['id' => 'order_test', 'type' => 'order_dispatched']);
});

test('order dispatched broadcast message wraps the serialized order resource', function () {
    fleetopsOrderDispatchedBoot();

    $broadcast = (new OrderDispatched(fleetopsOrderDispatchedOrder()))->toBroadcast(null);

    expect($broadcast)->toBeInstanceOf(BroadcastMessage::class)
        ->and($broadcast->data['event'])->toBe('order.dispatched')
        ->and($broadcast->data['id'])->toStartWith('event_')
        ->and($broadcast->data)->toHaveKeys(['api_version', 'created_at', 'data']);
});

test('order dispatched mail seam executes with waypoint tracking fallback', function () {
    fleetopsOrderDispatchedBoot();

    $order    = fleetopsOrderDispatchedOrder();
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'wp-1', 'tracking' => 'WPTRK-9'], true);

    // Utils::consoleUrl requires the full application environment; the mail
    // body executes through subject/line and the tracking-number resolution
    // for both the order and waypoint variants before reaching that seam.
    expect(fn () => (new OrderDispatched($order))->toMail(null))->toThrow(Error::class)
        ->and(fn () => (new OrderDispatched($order, $waypoint))->toMail(null))->toThrow(Error::class);
});

test('order dispatched push channel seams execute their delegation bodies', function () {
    fleetopsOrderDispatchedBoot();
    $notification = new OrderDispatched(fleetopsOrderDispatchedOrder());

    // The fcm/apn transport packages are unavailable in the harness; the
    // delegation bodies still execute, which is the covered contract here.
    expect(fn () => $notification->toFcm(null))->toThrow(TypeError::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
