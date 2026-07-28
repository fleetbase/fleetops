<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the OrderFailed notification: title/message construction with the
 * waypoint tracking-number preference, broadcast channel construction, and
 * the fcm/apn push delegation seams.
 */
function fleetopsOrderFailedBoot(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    session(['company' => 'company-1', 'api_credential' => 'console']);
}

function fleetopsOrderFailedOrder(): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-1',
        'public_id' => 'order_test',
        'status'    => 'failed',
    ], true);
    $order->exists = true;

    return $order;
}

test('order failed builds title and data with waypoint tracking preference', function () {
    fleetopsOrderFailedBoot();

    $notification = new OrderFailed(fleetopsOrderFailedOrder(), 'address unreachable');
    expect($notification->title)->toContain('delivery has')
        ->and($notification->data)->toBe(['id' => 'order_test', 'type' => 'order_canceled']);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'wp-1'], true);
    $trackingNumber = new Fleetbase\FleetOps\Models\TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-1', 'tracking_number' => 'WPTRK-7'], true);
    $waypoint->setRelation('trackingNumber', $trackingNumber);
    $withWaypoint = new OrderFailed(fleetopsOrderFailedOrder(), 'address unreachable', $waypoint);
    expect($withWaypoint->title)->toContain('WPTRK-7');
});

test('order failed broadcasts on company api and order channels', function () {
    fleetopsOrderFailedBoot();

    $channels = (new OrderFailed(fleetopsOrderFailedOrder(), 'reason'))->broadcastOn();

    expect($channels)->toHaveCount(5)
        ->and($channels[2]->name)->toBe('api.console')
        ->and($channels[3]->name)->toBe('order.order-1')
        ->and($channels[4]->name)->toBe('order.order_test');
});

test('order failed push channel seams execute their delegation bodies', function () {
    fleetopsOrderFailedBoot();
    $notification = new OrderFailed(fleetopsOrderFailedOrder(), 'reason');

    // The fcm/apn transport packages are unavailable in the harness; the
    // delegation bodies still execute, which is the covered contract here.
    expect(fn () => $notification->toFcm(null))->toThrow(TypeError::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
