<?php

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job) { return \FleetOpsOrderUnitDispatchRecorder::record($job); }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsOrderUnitDispatchRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\now')) {
    eval('namespace Fleetbase\FleetOps\Models; function now($tz = null) { return \Illuminate\Support\Carbon::now($tz); }');
}

if (!class_exists('Fleetbase\FleetOps\Events\OrderDispatched', false)) {
    eval('namespace Fleetbase\FleetOps\Events; class OrderDispatched { public function __construct(public $order) {} }');
}

if (!class_exists('FleetOpsOrderUnitDispatchRecorder', false)) {
    class FleetOpsOrderUnitDispatchRecorder
    {
        public static array $jobs   = [];
        public static array $events = [];

        public static function reset(): void
        {
            static::$jobs   = [];
            static::$events = [];
        }

        public static function record($job): object
        {
            static::$jobs[] = $job;

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

use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Illuminate\Support\Carbon;

class FleetOpsQueuedDispatchOrderFake extends Order
{
    public bool $quietSaved         = false;
    public array $calls             = [];
    public array $activityRows      = [];
    public array $statuses          = [];
    public ?OrderConfig $fakeConfig = null;

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function load($relations)
    {
        return $this;
    }

    public function saveQuietly(array $options = []): bool
    {
        $this->quietSaved = true;

        return true;
    }

    public function flushAttributesCache(): bool
    {
        return true;
    }

    public function config(): ?OrderConfig
    {
        return $this->fakeConfig;
    }

    public function notifyDriverAssigned(): void
    {
        $this->calls[] = 'notifyDriverAssigned';
    }

    public function setPreliminaryDistanceAndTime(): void
    {
        $this->calls[] = 'setPreliminaryDistanceAndTime';
    }

    public function purchaseServiceQuote($serviceQuote, $meta = [])
    {
        $this->calls[] = 'purchaseServiceQuote';
    }

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activityRows[] = $activity->code;

        return 'tracking_status_public';
    }

    public function setStatus(?string $status, $andSave = true)
    {
        $this->statuses[] = $status;
        $this->status     = $status;

        return $this;
    }
}

class FleetOpsQueuedDispatchConfigFake extends OrderConfig
{
    public function getDispatchActivity(): ?Activity
    {
        return new Activity(['code' => 'dispatched']);
    }
}

class FleetOpsQueuedDispatchJobProbe extends FinalizeApiOrderCreation
{
    public ?Order $order = null;
    public array $ready  = [];

    protected function findOrder(): ?Order
    {
        return $this->order;
    }

    protected function findServiceQuote(): ?ServiceQuote
    {
        return null;
    }

    protected function fireOrderReady(Order $order): void
    {
        $this->ready[] = $order->uuid;
    }
}

function fleetopsQueuedDispatchTrackingStatus(string $code): TrackingStatus
{
    $status       = new TrackingStatus();
    $status->code = $code;

    return $status;
}

function fleetopsQueuedDispatchOrder(array $attributes = [], array $trackingCodes = ['CREATED'], array $lifecycle = []): FleetOpsQueuedDispatchOrderFake
{
    $config = new FleetOpsQueuedDispatchConfigFake();
    $config->setRawAttributes(['meta' => json_encode($lifecycle ? ['lifecycle' => $lifecycle] : [])], true);

    $order = new FleetOpsQueuedDispatchOrderFake();
    $order->setRawAttributes(array_merge([
        'uuid'                 => 'order-uuid',
        'tracking_number_uuid' => 'tracking-number-uuid',
        'status'               => 'created',
        'dispatched'           => false,
        'started'              => false,
    ], $attributes), true);
    $order->fakeConfig = $config;
    $order->setRelation('trackingStatuses', collect(array_map('fleetopsQueuedDispatchTrackingStatus', $trackingCodes)));
    $order->setRelation('driverAssigned', null);
    $order->setRelation('payload', null);

    return $order;
}

function fleetopsRunQueuedDispatch(Order $order): FleetOpsQueuedDispatchJobProbe
{
    $job        = new FleetOpsQueuedDispatchJobProbe($order->uuid, null, true);
    $job->order = $order;
    $job->handle();

    return $job;
}

beforeEach(function () {
    FleetOpsOrderUnitDispatchRecorder::reset();
    Carbon::setTestNow(Carbon::parse('2026-10-11 09:30:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('queued api dispatch still dispatches an order that is waiting at created', function () {
    $order = fleetopsQueuedDispatchOrder();

    $job = fleetopsRunQueuedDispatch($order);

    expect($order->dispatched)->toBeTrue()
        ->and($order->dispatched_at->toDateTimeString())->toBe('2026-10-11 09:30:00')
        ->and($order->status)->toBe('dispatched')
        ->and($order->activityRows)->toBe(['dispatched'])
        ->and(FleetOpsOrderUnitDispatchRecorder::$events)->toHaveCount(1)
        ->and(FleetOpsOrderUnitDispatchRecorder::$events[0])->toBeInstanceOf(OrderDispatched::class)
        ->and($job->ready)->toBe(['order-uuid']);
});

test('queued api dispatch is a no-op once the order was dispatched and started', function () {
    $dispatchedAt = Carbon::parse('2026-10-11 09:00:00');
    $startedAt    = Carbon::parse('2026-10-11 09:10:00');
    $order        = fleetopsQueuedDispatchOrder([
        'status'        => 'started',
        'dispatched'    => true,
        'dispatched_at' => $dispatchedAt->toDateTimeString(),
        'started'       => true,
        'started_at'    => $startedAt->toDateTimeString(),
    ], ['CREATED', 'DISPATCHED', 'STARTED']);

    $job = fleetopsRunQueuedDispatch($order);

    expect($order->status)->toBe('started')
        ->and($order->dispatched_at->toDateTimeString())->toBe('2026-10-11 09:00:00')
        ->and($order->started_at->toDateTimeString())->toBe('2026-10-11 09:10:00')
        ->and($order->quietSaved)->toBeFalse()
        ->and($order->activityRows)->toBe([])
        ->and($order->statuses)->toBe([])
        ->and(FleetOpsOrderUnitDispatchRecorder::$jobs)->toBe([])
        ->and(FleetOpsOrderUnitDispatchRecorder::$events)->toBe([])
        ->and($order->calls)->toBe(['notifyDriverAssigned', 'setPreliminaryDistanceAndTime', 'purchaseServiceQuote'])
        ->and($job->ready)->toBe(['order-uuid']);
});

test('an order is only awaiting dispatch at its initial status with nothing recorded', function () {
    expect(fleetopsQueuedDispatchOrder()->isAwaitingDispatch())->toBeTrue()
        ->and(fleetopsQueuedDispatchOrder(['status' => null])->isAwaitingDispatch())->toBeTrue()
        ->and(fleetopsQueuedDispatchOrder(['status' => 'pending'], ['PENDING'], ['initial' => 'pending'])->isAwaitingDispatch())->toBeTrue()
        ->and(fleetopsQueuedDispatchOrder(['dispatched' => true])->isAwaitingDispatch())->toBeFalse()
        ->and(fleetopsQueuedDispatchOrder(['dispatched_at' => '2026-10-11 09:00:00'])->isAwaitingDispatch())->toBeFalse()
        ->and(fleetopsQueuedDispatchOrder(['started' => true])->isAwaitingDispatch())->toBeFalse()
        ->and(fleetopsQueuedDispatchOrder(['started_at' => '2026-10-11 09:10:00'])->isAwaitingDispatch())->toBeFalse()
        ->and(fleetopsQueuedDispatchOrder(['status' => 'enroute'])->isAwaitingDispatch())->toBeFalse()
        ->and(fleetopsQueuedDispatchOrder(['status' => 'completed'])->isAwaitingDispatch())->toBeFalse()
        ->and(fleetopsQueuedDispatchOrder([], ['CREATED', 'DISPATCHED'])->isAwaitingDispatch())->toBeFalse();

    $withoutConfig             = fleetopsQueuedDispatchOrder(['status' => 'pending']);
    $withoutConfig->fakeConfig = null;

    expect($withoutConfig->isAwaitingDispatch())->toBeFalse();
});

test('a terminal or later status on a custom lifecycle is never dispatched by the queued job', function () {
    $order = fleetopsQueuedDispatchOrder(['status' => 'delivered'], ['PENDING', 'DELIVERED'], ['initial' => 'pending', 'completed' => 'delivered']);

    fleetopsRunQueuedDispatch($order);

    expect($order->status)->toBe('delivered')
        ->and($order->dispatched)->toBeFalse()
        ->and($order->activityRows)->toBe([])
        ->and(FleetOpsOrderUnitDispatchRecorder::$events)->toBe([]);
});
