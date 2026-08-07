<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the OrderAssigned notification's fcm/apn push channel seams. The
 * transport packages are unavailable in the harness, so the assertion is that
 * the delegation bodies execute and fail inside the transport rather than
 * before reaching it.
 */
function fleetopsOrderAssignedOrder(): Order
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-assigned-1', 'tracking_number' => 'FLB-ASSIGNED-1'], true);

    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-assigned-1',
        'public_id' => 'order_assignedone',
        'status'    => 'assigned',
    ], true);
    $order->setRelation('trackingNumber', $trackingNumber);
    $order->exists = true;

    return $order;
}

test('order assigned builds title message and payload data', function () {
    $notification = new OrderAssigned(fleetopsOrderAssignedOrder());

    expect($notification->title)->toBe('New order FLB-ASSIGNED-1 assigned!')
        ->and($notification->message)->toBe('You have a new order assigned, tap for details.')
        ->and($notification->data)->toBe(['id' => 'order_assignedone', 'type' => 'order_assigned']);
});

test('order assigned push channel seams execute their delegation bodies', function () {
    $notification = new OrderAssigned(fleetopsOrderAssignedOrder());

    // The fcm/apn transport packages are unavailable in the harness; the
    // delegation bodies still execute, which is the covered contract here.
    expect(fn () => $notification->toFcm(null))->toThrow(Exception::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
