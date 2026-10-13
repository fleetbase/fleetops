<?php

use Fleetbase\FleetOps\Events\OrderOfferChanged;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Notifications\DriverNotAccepted;
use Fleetbase\FleetOps\Notifications\OrderChanged;
use Fleetbase\FleetOps\Notifications\OrderOfferDeclined;
use Fleetbase\FleetOps\Notifications\OrderUnassigned;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Covers the driver pushes and dispatch alerts for order changes, unassignment
 * and offers: their titles, bodies and the data the apps read.
 */
function fleetopsOrderChangeNotificationOrder(): Order
{
    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-1', 'tracking_number' => 'FLB-CHANGE-1'], true);

    $order = new Order();
    $order->setRawAttributes([
        'uuid'                 => 'order-uuid',
        'public_id'            => 'order_change',
        'company_uuid'         => 'company-1',
        'driver_assigned_uuid' => 'driver-uuid',
        'meta'                 => json_encode(['offer' => ['driver' => 'driver_one', 'status' => 'expired', 'expires_at' => '2026-10-13T12:05:00Z']]),
    ], true);
    $order->setRelation('trackingNumber', $trackingNumber);
    $order->setRelation('driverAssigned', null);

    return $order;
}

test('order changed pushes the changes to the driver with fcm safe data', function () {
    $notification = new OrderChanged(fleetopsOrderChangeNotificationOrder(), ['dropoff', 'items'], 'Drop-off changed to 8 Marina View · Items changed');

    expect($notification->title)->toBe('Order FLB-CHANGE-1 changed')
        ->and($notification->message)->toBe('Drop-off changed to 8 Marina View · Items changed')
        ->and($notification->data)->toBe(['type' => 'order_changed', 'id' => 'order_change', 'changes' => ['dropoff', 'items']])
        ->and($notification->fcmData())->toBe(['type' => 'order_changed', 'id' => 'order_change', 'changes' => 'dropoff,items'])
        ->and($notification->via(null))->toBe(['broadcast', FcmChannel::class, ApnChannel::class])
        ->and($notification->toArray())->toMatchArray(['event' => 'order.changed_notification', 'data' => $notification->data])
        ->and(array_map(fn ($channel) => $channel->name, $notification->broadcastOn()))->toContain('driver.driver-uuid')
        ->and((new OrderChanged(fleetopsOrderChangeNotificationOrder()))->message)->toBe('This order was changed, tap for details.');
});

test('order unassigned tells the driver the order is no longer theirs', function () {
    $notification = new OrderUnassigned(fleetopsOrderChangeNotificationOrder());

    expect($notification->title)->toBe('Order FLB-CHANGE-1 unassigned')
        ->and($notification->data)->toBe(['type' => 'order_unassigned', 'id' => 'order_change'])
        ->and($notification->via(null))->toBe([FcmChannel::class, ApnChannel::class])
        ->and($notification->toArray()['event'])->toBe('order.unassigned_notification');
});

test('offer alerts describe the declined or unanswered offer for dispatch', function () {
    $order    = fleetopsOrderChangeNotificationOrder();
    $declined = new OrderOfferDeclined($order, ['driver' => 'driver_one', 'driver_name' => 'Dee', 'reason' => 'Too far']);
    $silent   = new DriverNotAccepted($order, ['driver' => 'driver_one']);

    expect($declined->toArray(null))->toMatchArray([
        'event'    => 'order.offer_declined',
        'order_id' => 'order_change',
        'message'  => 'Dee declined order FLB-CHANGE-1: Too far',
    ])
        ->and($declined->via(null))->toBe(['mail', 'database'])
        ->and($silent->toArray(null))->toMatchArray([
            'event'    => 'order.driver_not_accepted',
            'order_id' => 'order_change',
            'context'  => ['driver' => 'driver_one'],
        ]);
});

test('offer changes broadcast as order offer status on the order and company channels', function () {
    $event = new OrderOfferChanged(fleetopsOrderChangeNotificationOrder(), Order::OFFER_EXPIRED);

    expect($event->broadcastAs())->toBe('order.offer_expired')
        ->and(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))->toBe(['order.order_change', 'order.order-uuid', 'company.company-1'])
        ->and($event->broadcastWith())->toMatchArray(['event' => 'order.offer_expired'])
        ->and($event->broadcastWith()['data'])->toBe([
            'id'     => 'order_change',
            'driver' => 'driver_one',
            'offer'  => ['driver' => 'driver_one', 'status' => 'expired', 'expires_at' => '2026-10-13T12:05:00Z'],
        ]);
});
