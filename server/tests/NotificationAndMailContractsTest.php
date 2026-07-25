<?php

use Fleetbase\FleetOps\Mail\MaintenanceScheduleReminder;
use Fleetbase\FleetOps\Mail\WorkOrderDispatched;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Notifications\LateDeparture;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;
use Fleetbase\Models\Model;

class FleetOpsNotificationOrderFake extends Order
{
    public string $uuid      = 'order-uuid';
    public string $public_id = 'order_public';
    public string $tracking  = 'TRACK-123';
}

class FleetOpsNotificationDriverFake extends Model
{
    public function getAttribute($key)
    {
        return match ($key) {
            'uuid'      => 'driver-uuid',
            'public_id' => 'driver-public',
            default     => parent::getAttribute($key),
        };
    }
}

function notificationTestOrder(): Order
{
    return new FleetOpsNotificationOrderFake();
}

function fleetOpsNotificationChannelNames(array $channels): array
{
    return array_map(fn ($channel) => $channel->name, $channels);
}

test('operational alert notifications expose expected channels and database payloads', function () {
    $order = notificationTestOrder();

    $lateDeparture     = new LateDeparture($order, ['grace_minutes' => 15]);
    $routeDeviation    = new RouteDeviation($order, ['deviation_meters' => 400]);
    $prolongedStoppage = new ProlongedStoppage($order, ['stopped_minutes' => 30]);

    expect(LateDeparture::$name)->toBe('Late Departure')
        ->and(RouteDeviation::$package)->toBe('fleet-ops')
        ->and(ProlongedStoppage::$description)->toContain('vehicle remains stopped')
        ->and($lateDeparture->via(null))->toBe(['mail', 'database'])
        ->and($routeDeviation->via(null))->toBe(['mail', 'database'])
        ->and($prolongedStoppage->via(null))->toBe(['mail', 'database'])
        ->and($lateDeparture->toArray(null))->toMatchArray([
            'event'      => 'order.late_departure',
            'order_id'   => 'order_public',
            'order_uuid' => 'order-uuid',
            'context'    => ['grace_minutes' => 15],
        ])
        ->and($routeDeviation->toArray(null))->toMatchArray([
            'event'      => 'order.route_deviation',
            'order_id'   => 'order_public',
            'order_uuid' => 'order-uuid',
            'context'    => ['deviation_meters' => 400],
        ])
        ->and($prolongedStoppage->toArray(null))->toMatchArray([
            'event'      => 'order.prolonged_stoppage',
            'order_id'   => 'order_public',
            'order_uuid' => 'order-uuid',
            'context'    => ['stopped_minutes' => 30],
        ]);
});

test('order dispatched notification exposes channels array and mail payloads', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $order = notificationTestOrder();
    $order->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company-public',
    ]);
    $order->setRelation('driverAssigned', (object) [
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver-public',
    ]);

    $notification = new OrderDispatched($order);

    expect(OrderDispatched::$name)->toBe('Order Dispatched')
        ->and(OrderDispatched::$package)->toBe('fleet-ops')
        ->and($notification->title)->toBe('Order TRACK-123 has been dispatched!')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_dispatched'])
        ->and($notification->via(null))->toContain('broadcast', 'mail')
        ->and($notification->broadcastType())->toBe('order.dispatched')
        ->and(fleetOpsNotificationChannelNames($notification->broadcastOn()))->toBe([
            'company.company-session',
            'company.company-public',
            'api.api-credential',
            'order.order-uuid',
            'order.order_public',
            'driver.driver-uuid',
            'driver.driver-public',
        ])
        ->and($notification->toArray())->toBe([
            'event' => 'order.dispatched_notification',
            'title' => 'Order TRACK-123 has been dispatched!',
            'body'  => 'An order has just been dispatched to you and is ready to be started.',
            'data'  => ['id' => 'order_public', 'type' => 'order_dispatched'],
        ]);
});

test('order ping notification formats distance and driver channels', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $order = notificationTestOrder();
    $order->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company-public',
    ]);
    $driver = new FleetOpsNotificationDriverFake();

    $notification = new OrderPing($order, 1500);
    $notification->order->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company-public',
    ]);

    expect(OrderPing::$name)->toBe('Order Ping')
        ->and($notification->message)->toContain('1.5 kilometers away')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_ping'])
        ->and($notification->via($driver))->toContain('broadcast')
        ->and($notification->broadcastType())->toBe('order.ping')
        ->and(fleetOpsNotificationChannelNames($notification->broadcastOn()))->toBe([
            'company.company-session',
            'company.company-public',
            'api.api-credential',
            'order.order-uuid',
            'order.order_public',
            'driver.driver-uuid',
            'driver.driver-public',
        ])
        ->and($notification->toArray())->toBe([
            'event' => 'order.ping_notification',
            'title' => 'New incoming order!',
            'body'  => $notification->message,
            'data'  => ['id' => 'order_public', 'type' => 'order_ping'],
        ]);
});

test('work order dispatched mail exposes subject and markdown context', function () {
    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes([
        'public_id' => 'WO-100',
        'subject'   => 'Replace brake pads',
    ], true);
    $assignee = (object) ['name' => 'Fleet Vendor'];
    $target   = (object) ['display_name' => 'Truck 12'];
    $workOrder->setRelation('assignee', $assignee);
    $workOrder->setRelation('target', $target);

    $mail    = new WorkOrderDispatched($workOrder);
    $content = $mail->content();

    expect($mail->workOrder)->toBe($workOrder)
        ->and($mail->envelope()->subject)->toBe('Work Order #WO-100: Replace brake pads')
        ->and($content->markdown)->toBe('fleetops::mail.work-order-dispatched')
        ->and($content->with)->toMatchArray([
            'workOrder' => $workOrder,
            'assignee'  => $assignee,
            'target'    => $target,
        ]);
});

test('maintenance schedule reminder mail exposes schedule context', function () {
    $schedule = new MaintenanceSchedule();
    $schedule->setRawAttributes([
        'name' => 'Quarterly inspection',
    ], true);
    $subject  = (object) ['display_name' => 'Truck 12'];
    $assignee = (object) ['name' => 'Maintenance Vendor'];
    $schedule->setRelation('subject', $subject);
    $schedule->setRelation('defaultAssignee', $assignee);

    $mail    = new MaintenanceScheduleReminder($schedule, 7);
    $content = $mail->content();

    expect($mail->schedule)->toBe($schedule)
        ->and($mail->offsetDays)->toBe(7)
        ->and($mail->envelope()->subject)->toContain('Maintenance Reminder: Quarterly inspection')
        ->and($mail->envelope()->subject)->toContain('Truck 12')
        ->and($content->markdown)->toBe('fleetops::mail.maintenance-schedule-reminder')
        ->and($content->with)->toMatchArray([
            'schedule'   => $schedule,
            'assignee'   => $assignee,
            'subject'    => $subject,
            'offsetDays' => 7,
        ]);
});
