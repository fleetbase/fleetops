<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Notifications\OrderChanged;
use Fleetbase\FleetOps\Notifications\OrderUnassigned;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Covers the driver pushes for order changes and unassignment: their titles,
 * bodies and the data the apps read.
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

    // The push builders reach for the FCM and APN clients, which the harness does not configure
    expect(fn () => $notification->toFcm(null))->toThrow(Exception::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});

test('order unassigned tells the driver the order is no longer theirs', function () {
    $notification = new OrderUnassigned(fleetopsOrderChangeNotificationOrder());

    expect($notification->title)->toBe('Order FLB-CHANGE-1 unassigned')
        ->and($notification->data)->toBe(['type' => 'order_unassigned', 'id' => 'order_change'])
        ->and($notification->via(null))->toBe([FcmChannel::class, ApnChannel::class])
        ->and($notification->toArray()['event'])->toBe('order.unassigned_notification');

    expect(fn () => $notification->toFcm(null))->toThrow(Exception::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
