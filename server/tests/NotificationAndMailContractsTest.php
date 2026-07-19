<?php

use Fleetbase\FleetOps\Mail\MaintenanceScheduleReminder;
use Fleetbase\FleetOps\Mail\WorkOrderDispatched;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Notifications\LateDeparture;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;

class FleetOpsNotificationOrderFake extends Order
{
    public string $uuid = 'order-uuid';
    public string $public_id = 'order_public';
    public string $tracking = 'TRACK-123';
}

function notificationTestOrder(): Order
{
    return new FleetOpsNotificationOrderFake();
}

test('operational alert notifications expose expected channels and database payloads', function () {
    $order = notificationTestOrder();

    $lateDeparture = new LateDeparture($order, ['grace_minutes' => 15]);
    $routeDeviation = new RouteDeviation($order, ['deviation_meters' => 400]);
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

test('work order dispatched mail exposes subject and markdown context', function () {
    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes([
        'public_id' => 'WO-100',
        'subject'   => 'Replace brake pads',
    ], true);
    $assignee = (object) ['name' => 'Fleet Vendor'];
    $target = (object) ['display_name' => 'Truck 12'];
    $workOrder->setRelation('assignee', $assignee);
    $workOrder->setRelation('target', $target);

    $mail = new WorkOrderDispatched($workOrder);
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
    $subject = (object) ['display_name' => 'Truck 12'];
    $assignee = (object) ['name' => 'Maintenance Vendor'];
    $schedule->setRelation('subject', $subject);
    $schedule->setRelation('defaultAssignee', $assignee);

    $mail = new MaintenanceScheduleReminder($schedule, 7);
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
