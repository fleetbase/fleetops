<?php

if (!class_exists('FleetOpsOrderCancelReasonRecorder', false)) {
    class FleetOpsOrderCancelReasonRecorder
    {
        public static array $events = [];

        public static function dispatch($job): object
        {
            if ($job instanceof Closure) {
                $job();
            }

            return new class {
                public function afterCommit(): self
                {
                    return $this;
                }
            };
        }
    }
}

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job) { return \FleetOpsOrderCancelReasonRecorder::dispatch($job); }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsOrderCancelReasonRecorder::$events[] = $event; return $event; }');
}

if (!class_exists('Fleetbase\FleetOps\Events\OrderCanceled', false)) {
    eval('namespace Fleetbase\FleetOps\Events; class OrderCanceled { public $activity = null; public $waypoint = null; public function __construct(public $order) {} public function getModelRecord() { return $this->order; } }');
}

use Fleetbase\FleetOps\Events\OrderCanceled;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Listeners\HandleOrderCanceled;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;

/**
 * Covers canceling an order with a reason: the reason becomes the canceled
 * status details and rides on the OrderCanceled event, while the config's
 * activity is left untouched and a plain cancel carries no activity.
 */
class FleetOpsOrderCancelReasonOrder extends Order
{
    public array $activities = [];

    public function updateActivity(?Activity $activity = null, $proof = null): Order
    {
        $this->activities[] = $activity;
        $this->status       = $activity?->code;

        return $this;
    }
}

class FleetOpsOrderCancelReasonConfig extends OrderConfig
{
    public ?Activity $canceled = null;

    public function getCanceledActivity()
    {
        return $this->canceled;
    }
}

function fleetopsOrderCancelReasonOrder(): array
{
    FleetOpsOrderCancelReasonRecorder::$events = [];

    $config           = new FleetOpsOrderCancelReasonConfig();
    $config->canceled = new Activity(['code' => 'canceled', 'status' => 'Order canceled', 'details' => 'Order was canceled']);

    $order = new FleetOpsOrderCancelReasonOrder();
    $order->setRawAttributes(['uuid' => 'order-uuid', 'status' => 'dispatched'], true);
    $order->setRelation('orderConfig', $config);

    return [$order, $config];
}

test('canceling with a reason records it as the canceled details and on the event', function () {
    [$order, $config] = fleetopsOrderCancelReasonOrder();

    $order->cancelWithReason('  Customer moved house  ');

    $event = collect(FleetOpsOrderCancelReasonRecorder::$events)->first(fn ($event) => $event instanceof OrderCanceled);

    expect($order->status)->toBe('canceled')
        ->and($order->activities[0]->get('details'))->toBe('Customer moved house')
        ->and($config->canceled->get('details'))->toBe('Order was canceled')
        ->and($event)->not->toBeNull()
        ->and($event->activity->get('details'))->toBe('Customer moved house');
});

test('a plain cancel keeps the config activity and sends no reason', function () {
    [$order, $config] = fleetopsOrderCancelReasonOrder();

    $order->cancel();

    $event = collect(FleetOpsOrderCancelReasonRecorder::$events)->first(fn ($event) => $event instanceof OrderCanceled);

    expect($order->activities[0])->toBe($config->canceled)
        ->and($event->activity)->toBeNull();
});

test('the canceled listener passes the reason on to the assigned driver', function () {
    [$order] = fleetopsOrderCancelReasonOrder();
    $order->setRawAttributes(array_merge($order->getAttributes(), ['driver_assigned_uuid' => 'driver-uuid']));
    $order->setRelation('facilitator', null);
    $order->setRelation('trackingNumber', null);

    $driver = new class extends Driver {
        public array $notified = [];

        public function notify($instance)
        {
            $this->notified[] = $instance;
        }
    };

    $listener = new class($driver) extends HandleOrderCanceled {
        public function __construct(private Driver $driverForTest)
        {
        }

        protected function findAssignedDriver($order): ?Driver
        {
            return $this->driverForTest;
        }
    };

    $event           = new OrderCanceled($order);
    $event->activity = new Activity(['code' => 'canceled', 'details' => 'Customer moved house']);
    $listener->handle($event);

    expect($driver->notified)->toHaveCount(1)
        ->and($driver->notified[0]->reason)->toBe('Customer moved house')
        ->and($driver->notified[0]->data)->toBe(['id' => null, 'type' => 'order_canceled', 'reason' => 'Customer moved house']);
});
