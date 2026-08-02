<?php

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return 'https://fleetops.test' . $path;
    }
}

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { return $key === null ? new class { public function missing($key): bool { return true; } } : $default; }');
}

use Fleetbase\FleetOps\Events\OrderDispatchFailed as OrderDispatchFailedEvent;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Mail\CustomerCredentialsMail;
use Fleetbase\FleetOps\Mail\MaintenanceScheduleReminder;
use Fleetbase\FleetOps\Mail\WorkOrderDispatched;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Notifications\DriverArrivedAtGeofence;
use Fleetbase\FleetOps\Notifications\DriverShiftChanged;
use Fleetbase\FleetOps\Notifications\LateDeparture;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled as OrderCanceledNotification;
use Fleetbase\FleetOps\Notifications\OrderCompleted as OrderCompletedNotification;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed;
use Fleetbase\FleetOps\Notifications\OrderFailed as OrderFailedNotification;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\OrderSplit;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\FleetOps\Support\OrderTracker;
use Fleetbase\FleetOps\Support\Payment;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\Models\Model;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Stripe\StripeClient;

if (!class_exists(StripeClient::class, false)) {
    eval('namespace Stripe; class StripeClient { public function __construct(public array $config = []) {} }');
}

class FleetOpsNotificationOrderFake extends Order
{
    public string $uuid      = 'order-uuid';
    public string $public_id = 'order_public';
    public string $tracking  = 'TRACK-123';

    public function getAttribute($key)
    {
        if ($key === 'scheduled_at') {
            return $this->attributes['scheduled_at'] ?? null;
        }

        return parent::getAttribute($key);
    }

    public function getIsScheduledAttribute(): bool
    {
        return !empty($this->attributes['scheduled_at'] ?? null);
    }
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

class FleetOpsNotificationScheduleItemFake extends ScheduleItem
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }
}

class FleetOpsNotificationContactFake extends Contact
{
    public User $userForTest;

    public function getUser(): ?User
    {
        return $this->userForTest;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsOrderDispatchFailedEventFake extends OrderDispatchFailedEvent
{
    public function __construct(private string $reasonForTest)
    {
    }

    public function getReason(): string
    {
        return $this->reasonForTest;
    }
}

function notificationTestOrder(): Order
{
    return new FleetOpsNotificationOrderFake();
}

function notificationTestOrderWithRelations(array $attributes = []): Order
{
    $order = notificationTestOrder();
    $order->setRawAttributes(array_merge([
        'uuid'         => 'order-uuid',
        'public_id'    => 'order_public',
        'scheduled_at' => null,
    ], $attributes), true);
    $order->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company-public',
    ]);
    $order->setRelation('driverAssigned', (object) [
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver-public',
    ]);
    $order->setRelation('trackingNumber', (object) [
        'tracking_number' => 'TN-ASSIGNED',
    ]);

    return $order;
}

function fleetOpsNotificationChannelNames(array $channels): array
{
    return array_map(fn ($channel) => $channel->name, $channels);
}

function fleetOpsNotificationWithEnvironment(callable $callback): mixed
{
    $previousApp = Container::getInstance();
    $app         = new class extends Container {
        public function environment(...$environments)
        {
            return false;
        }
    };

    if ($previousApp->bound('config')) {
        $app->instance('config', $previousApp->make('config'));
    }

    Container::setInstance($app);

    try {
        return $callback();
    } finally {
        Container::setInstance($previousApp);
    }
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

test('order split notification payment client and order tracker expose support contracts', function () {
    $notification = new OrderSplit();
    $mail         = $notification->toMail(null);

    expect(OrderSplit::$name)->toBe('Order Split')
        ->and($notification->via(null))->toBe(['mail'])
        ->and($notification->toArray(null))->toBe([])
        ->and($mail->introLines)->toContain('The introduction to the notification.')
        ->and($mail->actionText)->toBe('Notification Action');

    expect(Payment::getStripeClient(['api_key' => 'sk_test_fleetops']))->toBeInstanceOf(StripeClient::class);

    $order = notificationTestOrder();
    app()->instance(TrackingIntelligenceService::class, new class {
        public array $calls = [];

        public function eta(Order $order, mixed $options): array
        {
            $this->calls[] = ['eta', $order->public_id, $options->provider, $options->fallbacks];

            return ['eta' => 12];
        }

        public function track(Order $order, mixed $options): array
        {
            $this->calls[] = ['track', $order->public_id, $options->provider, $options->fallbacks];

            return ['status' => 'tracked'];
        }
    });

    $tracker = new OrderTracker($order);
    $service = app(TrackingIntelligenceService::class);

    expect($tracker->eta(['provider' => 'mock', 'fallbacks' => 'a,b']))->toBe(['eta' => 12])
        ->and($tracker->toArray(['provider' => 'mock']))->toBe(['status' => 'tracked'])
        ->and($service->calls)->toBe([
            ['eta', 'order_public', 'mock', ['a', 'b']],
            ['track', 'order_public', 'mock', []],
        ]);
});

test('driver arrived at geofence notification exposes mail and database payloads', function () {
    $order = notificationTestOrder();
    $order->setRawAttributes([
        'uuid'            => 'order-uuid',
        'public_id'       => 'order_public',
        'tracking_number' => 'TRACK-GEOFENCE',
    ], true);

    $geofence = (object) [
        'public_id' => 'zone_public',
        'name'      => 'Central Depot',
    ];

    $notification = new DriverArrivedAtGeofence($order, $geofence);
    $mail         = $notification->toMail(null);

    expect($notification->via(null))->toBe(['mail', 'database'])
        ->and($mail->subject)->toBe('Your driver has arrived — Order #order_public')
        ->and($mail->introLines)->toContain(
            'Your driver has arrived at Central Depot for order #order_public.',
            'Please be ready to receive your delivery.'
        )
        ->and($mail->outroLines)->toContain('Thank you for using our service.')
        ->and($mail->actionText)->toBe('Track Your Order')
        ->and($mail->actionUrl)->toContain('/tracking/TRACK-GEOFENCE')
        ->and($notification->toArray(null))->toBe([
            'event'         => 'driver.arrived_at_geofence',
            'order_id'      => 'order_public',
            'order_uuid'    => 'order-uuid',
            'geofence_id'   => 'zone_public',
            'geofence_name' => 'Central Depot',
            'message'       => 'Your driver has arrived at Central Depot for order #order_public.',
        ]);

    $fallback = new DriverArrivedAtGeofence($order, (object) []);

    expect($fallback->toArray(null))->toMatchArray([
        'geofence_id'   => null,
        'geofence_name' => null,
        'message'       => 'Your driver has arrived at your location for order #order_public.',
    ]);
});

test('driver shift changed notification formats new and updated shift messages', function () {
    $scheduleItem = new FleetOpsNotificationScheduleItemFake();
    $scheduleItem->setRawAttributes([
        'public_id' => 'schedule_public',
        'notes'     => 'Bring safety vest',
    ], true);
    $scheduleItem->start_at = Carbon::parse('2026-09-01 08:00:00');
    $scheduleItem->end_at   = Carbon::parse('2026-09-01 17:30:00');

    $created = new DriverShiftChanged($scheduleItem, true);
    $updated = new DriverShiftChanged($scheduleItem, false);
    $mail    = fleetOpsNotificationWithEnvironment(fn () => $created->toMail(null));

    expect(DriverShiftChanged::$name)->toBe('Driver Shift Changed')
        ->and(DriverShiftChanged::$package)->toBe('fleet-ops')
        ->and($created->via(null))->toBe(['mail'])
        ->and($created->title)->toBe('New shift scheduled')
        ->and($created->message)->toContain('A new shift has been added')
        ->and($created->message)->toContain('Tue, Sep 1 8:00am')
        ->and($created->message)->toContain('5:30pm')
        ->and($created->toArray(null))->toMatchArray([
            'id'       => 'schedule_public',
            'type'     => 'driver_shift_changed',
            'is_new'   => true,
        ])
        ->and($created->toArray(null)['start_at'])->not->toBeNull()
        ->and($created->toArray(null)['end_at'])->not->toBeNull()
        ->and($mail->subject)->toBe('New shift scheduled')
        ->and($mail->introLines)->toContain(
            $created->message,
            'Notes: Bring safety vest'
        )
        ->and($mail->actionText)->toBe('View Schedule')
        ->and($mail->actionUrl)->toContain('fleet-ops/operations/scheduler')
        ->and($updated->title)->toBe('Your shift has been updated')
        ->and($updated->message)->toContain('Your shift has been updated')
        ->and($updated->toArray(null)['is_new'])->toBeFalse();
});

test('order dispatch failed notification exposes channels array and mail payloads', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $order = notificationTestOrderWithRelations();
    $order->setRelation('trackingNumber', (object) [
        'tracking_number' => 'TN-FAILED',
    ]);
    $event        = new FleetOpsOrderDispatchFailedEventFake('No driver accepted the order');
    $notification = new OrderDispatchFailed($order, $event);
    $mail         = fleetOpsNotificationWithEnvironment(fn () => $notification->toMail(null));

    expect(OrderDispatchFailed::$name)->toBe('Order dispatch Failed')
        ->and(OrderDispatchFailed::$description)->toContain('dispatch')
        ->and($notification->title)->toBe('Order TN-FAILED dispatch has failed!')
        ->and($notification->message)->toBe('No driver accepted the order')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_dispatch_failed'])
        ->and($notification->via(null))->toBe(['mail'])
        ->and(fleetOpsNotificationChannelNames($notification->broadcastOn()))->toBe([
            'company.company-session',
            'company.company-public',
            'api.api-credential',
            'order.order-uuid',
            'order.order_public',
        ])
        ->and($notification->toArray())->toBe([
            'event' => 'order.assigned_failed_notification',
            'title' => 'Order TN-FAILED dispatch has failed!',
            'body'  => 'No driver accepted the order',
            'data'  => ['id' => 'order_public', 'type' => 'order_dispatch_failed'],
        ])
        ->and($mail->subject)->toBe('Order TN-FAILED dispatch has failed!')
        ->and($mail->introLines)->toBe(['No driver accepted the order'])
        ->and($mail->actionText)->toBe('Track Order')
        ->and($mail->actionUrl)->toContain('track-order')
        ->and($mail->actionUrl)->toContain('TN-FAILED');
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

test('order assigned notification handles scheduled and unscheduled contracts', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $order        = notificationTestOrderWithRelations();
    $notification = new OrderAssigned($order);
    $mail         = fleetOpsNotificationWithEnvironment(fn () => $notification->toMail(null));

    expect(OrderAssigned::$name)->toBe('Order Assigned')
        ->and(OrderAssigned::$package)->toBe('fleet-ops')
        ->and($notification->title)->toBe('New order TN-ASSIGNED assigned!')
        ->and($notification->message)->toBe('You have a new order assigned, tap for details.')
        ->and($notification->data)->toBe(['id' => 'order_public', 'type' => 'order_assigned'])
        ->and($notification->via(null))->toContain('broadcast', 'mail')
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
            'event' => 'order.assigned_notification',
            'title' => 'New order TN-ASSIGNED assigned!',
            'body'  => 'You have a new order assigned, tap for details.',
            'data'  => ['id' => 'order_public', 'type' => 'order_assigned'],
        ])
        ->and($mail->subject)->toBe('New order TN-ASSIGNED assigned!')
        ->and($mail->introLines)->toBe(['You have a new order assigned, tap for details.'])
        ->and($mail->actionText)->toBe('Track Order')
        ->and($mail->actionUrl)->toContain('track-order')
        ->and($mail->actionUrl)->toContain('TN-ASSIGNED');

    $scheduledOrder      = notificationTestOrderWithRelations(['scheduled_at' => '2026-08-01 10:30:00']);
    $scheduled           = new OrderAssigned($scheduledOrder);
    $scheduledMail       = fleetOpsNotificationWithEnvironment(fn () => $scheduled->toMail(null));

    expect($scheduled->message)->toBe('You have a new order scheduled for 2026-08-01 10:30:00')
        ->and($scheduledMail->introLines)->toBe([
            'You have a new order scheduled for 2026-08-01 10:30:00',
            'Dispatch is scheduled for 2026-08-01 10:30:00',
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

test('terminal order notifications expose shared broadcast and array payload contracts', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $order = notificationTestOrder();
    $order->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company-public',
    ]);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes([
        'public_id' => 'waypoint_public',
    ], true);
    $waypoint->setRelation('trackingNumber', (object) [
        'tracking_number' => 'WP-TRACK-123',
    ]);

    $canceled  = new OrderCanceledNotification($order, 'customer requested cancel', $waypoint);
    $failed    = new OrderFailedNotification($order, 'recipient unavailable', $waypoint);
    $completed = new OrderCompletedNotification($order, $waypoint);

    expect(OrderCanceledNotification::$name)->toBe('Order Canceled')
        ->and(OrderFailedNotification::$description)->toContain('failed')
        ->and(OrderCompletedNotification::$package)->toBe('fleet-ops')
        ->and($canceled->via(null))->toContain('broadcast', 'mail')
        ->and($failed->via(null))->toContain('broadcast', 'mail')
        ->and($completed->via(null))->toContain('broadcast', 'mail')
        ->and(fleetOpsNotificationChannelNames($canceled->broadcastOn()))->toBe([
            'company.company-session',
            'company.company-public',
            'api.api-credential',
            'order.order-uuid',
            'order.order_public',
        ])
        ->and($canceled->toArray())->toMatchArray([
            'event' => 'order.canceled_notification',
            'title' => 'Order WP-TRACK-123 was canceled',
            'body'  => 'Order WP-TRACK-123 has been canceled. customer requested cancel',
            'data'  => ['id' => 'order_public', 'type' => 'order_canceled'],
        ])
        ->and($failed->toArray())->toMatchArray([
            'event' => 'order.failed_notification',
            'title' => 'Order WP-TRACK-123 delivery has has failed',
            'body'  => 'Order WP-TRACK-123 delivery has failed. recipient unavailable',
            'data'  => ['id' => 'order_public', 'type' => 'order_canceled'],
        ])
        ->and($completed->toArray())->toMatchArray([
            'event' => 'order.completed_notification',
            'title' => 'Order WP-TRACK-123 has been completed.',
            'body'  => 'Order WP-TRACK-123 has been completed by agent.',
            'data'  => ['id' => 'order_public', 'type' => 'order_completed'],
        ]);
});

test('waypoint completed notification exposes channels array and mail payloads', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes([
        'uuid'         => 'waypoint-uuid',
        'public_id'    => 'waypoint_public',
        'payload_uuid' => 'missing-payload',
    ], true);
    $waypoint->setRelation('trackingNumber', (object) [
        'tracking_number' => 'WP-COMPLETE',
    ]);

    $activity     = new Activity(['details' => 'Dropoff completed']);
    $notification = new WaypointCompleted($waypoint, $activity);
    $mail         = fleetOpsNotificationWithEnvironment(fn () => $notification->toMail(null));

    expect(WaypointCompleted::$name)->toBe('Waypoint Completed')
        ->and(WaypointCompleted::$package)->toBe('fleet-ops')
        ->and($notification->title)->toBe('Order WP-COMPLETE dropoff completed')
        ->and($notification->message)->toBe('Dropoff completed')
        ->and($notification->data)->toBe(['id' => 'waypoint_public', 'type' => 'waypoint_completed'])
        ->and($notification->via(null))->toContain('broadcast', 'mail')
        ->and($notification->toArray())->toBe([
            0       => 'event.waypoint_completed_notification',
            'title' => 'Order WP-COMPLETE dropoff completed',
            'body'  => 'Dropoff completed',
            'data'  => ['id' => 'waypoint_public', 'type' => 'waypoint_completed'],
        ])
        ->and($mail->subject)->toBe('Order WP-COMPLETE dropoff completed')
        ->and($mail->introLines)->toBe([
            'Dropoff completed',
            'No further action is necessary.',
        ])
        ->and($mail->actionText)->toBe('Track Order')
        ->and($mail->actionUrl)->toContain('track-order')
        ->and($mail->actionUrl)->toContain('WP-COMPLETE');
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

test('customer credentials mail exposes subject content and portal fallback', function () {
    app('config')->set('fleetbase.console.host', 'console.fleetbase.test');
    app('config')->set('fleetbase.console.secure', true);
    app('config')->set('fleetbase.console.subdomain', null);
    app('session.store')->forget('company');

    $customer              = new FleetOpsNotificationContactFake();
    $customer->userForTest = new User();
    $customer->userForTest->setRawAttributes(['email' => 'customer@example.test'], true);
    $customer->setRelation('company', (object) ['name' => 'Acme Logistics']);

    $mail     = new CustomerCredentialsMail('plain-secret', $customer);
    $envelope = $mail->envelope();
    $content  = fleetOpsNotificationWithEnvironment(fn () => $mail->content());

    expect($envelope->subject)->toBe('Your Acme Logistics customer portal access is ready')
        ->and($content->markdown)->toBe('fleetops::mail.customer-credentials')
        ->and($content->with['customer'])->toBe($customer)
        ->and($content->with['plaintextPassword'])->toBe('plain-secret')
        ->and($content->with['customerPortalUrl'])->toBe('https://console.fleetbase.test/customer-portal')
        ->and($customer->getRelation('user'))->toBe($customer->userForTest);
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
