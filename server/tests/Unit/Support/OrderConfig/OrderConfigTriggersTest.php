<?php

use Fleetbase\FleetOps\Jobs\RunOrderConfigAction;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\Action;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigActions;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTriggers;

/**
 * Order config triggers and actions without a database: which triggers run for an event or an
 * activity, their `when` conditions, queueing, the action registry, and the placeholders the
 * notify actions fill in.
 */
if (!function_exists('Fleetbase\FleetOps\Support\OrderConfig\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Support\OrderConfig; function dispatch($job) { \OrderConfigTriggersTestRecorder::$queued[] = $job; return $job; }');
}

class OrderConfigTriggersTestRecorder
{
    public static array $ran    = [];
    public static array $queued = [];
}

class OrderConfigTriggersTestAction extends Action
{
    protected string $name        = 'record';
    protected string $label       = 'Record';
    protected string $description = 'Records that it ran.';
    protected array $parameters   = ['note' => 'Anything.'];

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        OrderConfigTriggersTestRecorder::$ran[] = [$order->uuid, $params, $context];
    }
}

class OrderConfigTriggersTestFailingAction extends Action
{
    protected string $name        = 'explode';
    protected string $label       = 'Explode';
    protected string $description = 'Always fails.';

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        throw new RuntimeException('boom');
    }
}

function orderConfigTriggersBoot(): void
{
    app()->instance('log', new class {
        public array $warnings = [];

        public function warning($message, array $context = [])
        {
            $this->warnings[] = $message;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    OrderConfigActions::register(OrderConfigTriggersTestAction::class);
    OrderConfigActions::register(OrderConfigTriggersTestFailingAction::class);
    OrderConfigTriggers::$runImmediately     = true;
    OrderConfigTriggersTestRecorder::$ran    = [];
    OrderConfigTriggersTestRecorder::$queued = [];
}

/** An order with its config already loaded, so triggers are read without a database. */
function orderConfigTriggersOrder(array $triggers, array $attributes = []): Order
{
    $config = new OrderConfig(['triggers' => $triggers]);
    $config->setAttribute('uuid', 'config-1');

    $order = new Order();
    $order->setRawAttributes(array_merge(['uuid' => 'order-1', 'public_id' => 'order_one', 'order_config_uuid' => 'config-1', 'status' => 'started'], $attributes), true);
    $order->setRelation('orderConfig', $config);

    return $order;
}

function orderConfigTriggersStatusIs(string $status): array
{
    return [['type' => 'and', 'conditions' => [['field' => 'status', 'operator' => 'equal', 'value' => $status]]]];
}

test('an event runs the config\'s registered actions for that event, with their params and the event', function () {
    orderConfigTriggersBoot();
    $order = orderConfigTriggersOrder([
        ['event' => OrderConfigTriggers::ORDER_COMPLETED, 'action' => 'record', 'params' => ['note' => 'first']],
        ['event' => OrderConfigTriggers::ORDER_COMPLETED, 'action' => 'send_invoice'],
        ['event' => OrderConfigTriggers::ORDER_COMPLETED, 'action' => 'record', 'params' => 'not a list'],
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'record'],
        ['event' => OrderConfigTriggers::ORDER_COMPLETED],
        'not a trigger',
    ]);

    $ran = OrderConfigTriggers::fire($order, OrderConfigTriggers::ORDER_COMPLETED, ['source' => 'test']);

    expect($ran)->toBe(2)
        ->and(OrderConfigTriggersTestRecorder::$ran)->toBe([
            ['order-1', ['note' => 'first'], ['event' => 'order.completed', 'source' => 'test']],
            ['order-1', [], ['event' => 'order.completed', 'source' => 'test']],
        ])
        ->and(OrderConfigTriggers::forEvent($order, OrderConfigTriggers::ORDER_CANCELED))->toHaveCount(1)
        ->and(OrderConfigTriggers::fire($order, OrderConfigTriggers::ORDER_CREATED))->toBe(0)
        ->and(OrderConfigTriggers::EVENTS)->toBe(['order.created', 'order.completed', 'order.canceled', 'dispatch.offer_exhausted', 'driver.approaching']);
});

test('a trigger runs only when every one of its conditions passes', function () {
    orderConfigTriggersBoot();
    $triggers = [
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'record', 'params' => ['note' => 'while started'], 'when' => orderConfigTriggersStatusIs('started')],
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'record', 'params' => ['note' => 'while arrived'], 'when' => orderConfigTriggersStatusIs('arrived')],
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'record', 'params' => ['note' => 'both'], 'when' => [...orderConfigTriggersStatusIs('started'), ...orderConfigTriggersStatusIs('arrived')]],
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'record', 'params' => ['note' => 'unreadable'], 'when' => [['type' => 'sometimes', 'conditions' => []]]],
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'record', 'params' => ['note' => 'empty'], 'when' => []],
    ];

    $ran = OrderConfigTriggers::fire(orderConfigTriggersOrder($triggers), OrderConfigTriggers::ORDER_CANCELED);

    expect($ran)->toBe(2)
        ->and(array_column(array_column(OrderConfigTriggersTestRecorder::$ran, 1), 'note'))->toBe(['while started', 'empty'])
        ->and(app('log')->warnings)->toContain('Skipped an order config trigger whose conditions could not be read')
        ->and(OrderConfigTriggers::passes(orderConfigTriggersOrder([]), ['when' => 'not a list']))->toBeTrue();
});

test('reaching an activity runs that activity\'s actions, with the activity in their context', function () {
    orderConfigTriggersBoot();
    $order    = orderConfigTriggersOrder([]);
    $activity = [
        'code'    => 'arrived',
        'status'  => 'Driver has arrived',
        'details' => 'Waiting at the pickup.',
        'actions' => [['action' => 'record', 'params' => ['note' => 'arrived']], ['action' => 'record', 'when' => orderConfigTriggersStatusIs('completed')]],
    ];

    expect(OrderConfigTriggers::activity($order, $activity))->toBe(1)
        ->and(OrderConfigTriggersTestRecorder::$ran)->toBe([
            ['order-1', ['note' => 'arrived'], ['event' => 'order.activity', 'activity' => ['code' => 'arrived', 'status' => 'Driver has arrived', 'details' => 'Waiting at the pickup.']]],
        ])
        ->and(OrderConfigTriggers::activity($order, null))->toBe(0)
        ->and(OrderConfigTriggers::activity($order, new Fleetbase\FleetOps\Flow\Activity(['code' => 'quiet'])))->toBe(0);
});

test('an order without a config of its own runs no triggers', function () {
    orderConfigTriggersBoot();
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-2', 'order_config_uuid' => null, 'status' => 'completed'], true);

    expect($order->currentConfig())->toBeNull()
        ->and(OrderConfigTriggers::forEvent($order, OrderConfigTriggers::ORDER_COMPLETED))->toBe([])
        ->and(OrderConfigTriggers::fire($order, OrderConfigTriggers::ORDER_COMPLETED))->toBe(0)
        ->and(OrderConfigTriggers::driverMoved(new Driver(), $order))->toBe(0)
        ->and(OrderConfigTriggers::driverMoved(new Driver(), null))->toBe(0);
});

test('actions are queued unless asked to run now, and a failing action is logged and not counted', function () {
    orderConfigTriggersBoot();
    $order = orderConfigTriggersOrder([
        ['event' => OrderConfigTriggers::ORDER_CREATED, 'action' => 'record', 'params' => ['note' => 'later']],
        ['event' => OrderConfigTriggers::ORDER_CREATED, 'action' => 'explode'],
    ]);

    $failed = OrderConfigTriggers::fire($order, OrderConfigTriggers::ORDER_CREATED);

    OrderConfigTriggers::$runImmediately = false;
    $queued                              = OrderConfigTriggers::fire($order, OrderConfigTriggers::ORDER_CREATED);
    OrderConfigTriggers::$runImmediately = true;
    $job                                 = OrderConfigTriggersTestRecorder::$queued[0];

    expect($failed)->toBe(1)
        ->and(app('log')->warnings)->toBe(['An order config action failed'])
        ->and($queued)->toBe(2)
        ->and(OrderConfigTriggersTestRecorder::$queued)->toHaveCount(2)
        ->and($job)->toBeInstanceOf(RunOrderConfigAction::class)
        ->and([$job->orderUuid, $job->action, $job->params, $job->context])->toBe(['order-1', 'record', ['note' => 'later'], ['event' => 'order.created']])
        ->and(OrderConfigTriggersTestRecorder::$queued[1]->action)->toBe('explode')
        ->and(OrderConfigTriggersTestRecorder::$ran)->toHaveCount(1);
});

test('the action registry lists the built-in actions and takes only real actions', function () {
    orderConfigTriggersBoot();
    $described = collect(OrderConfigActions::describe())->keyBy('name');

    expect(OrderConfigActions::names())->toContain('notify_customer', 'notify_driver', 'open_chat', 'close_chat', 'set_meta', 'cancel', 'record')
        ->and(OrderConfigActions::has('notify_customer'))->toBeTrue()
        ->and(OrderConfigActions::has('send_invoice'))->toBeFalse()
        ->and(OrderConfigActions::has(null))->toBeFalse()
        ->and(OrderConfigActions::get('send_invoice'))->toBeNull()
        ->and(OrderConfigActions::get('record'))->toBeInstanceOf(OrderConfigTriggersTestAction::class)
        ->and($described['notify_customer']['label'])->toBe('Notify customer')
        ->and(array_keys($described['notify_customer']['parameters']))->toBe(['title', 'message'])
        ->and(array_keys($described['set_meta']['parameters']))->toBe(['key', 'value'])
        ->and($described['record'])->toBe(['name' => 'record', 'label' => 'Record', 'description' => 'Records that it ran.', 'parameters' => ['note' => 'Anything.']])
        ->and(fn () => OrderConfigActions::register(stdClass::class))->toThrow(InvalidArgumentException::class);
});

test('placeholders are filled from the order, its driver, customer and the activity', function () {
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_one', 'status' => 'arrived'], true);
    $order->setRelation('driverAssigned', new class extends Driver {
        public function getNameAttribute()
        {
            return 'Chen Wei Tan';
        }
    });
    $customer = new Contact();
    $customer->setRawAttributes(['name' => 'Sam Lee'], true);
    $order->setRelation('customer', $customer);
    $tracking = new TrackingNumber();
    $tracking->setRawAttributes(['tracking_number' => 'TRK1'], true);
    $order->setRelation('trackingNumber', $tracking);
    $context = ['activity' => ['status' => 'Driver has arrived', 'details' => 'Waiting.']];

    $everything = Action::render('{driver.name}|{driver.first_name}|{customer.name}|{order.id}|{order.tracking_number}|{order.status}|{activity.status}|{activity.details}', $order, $context);

    $nobody = new Order();
    $nobody->setRawAttributes(['public_id' => 'order_two', 'driver_assigned_uuid' => null], true);
    $nobody->setRelation('driverAssigned', null);
    $nobody->setRelation('customer', null);
    $nobody->setRelation('trackingNumber', null);

    expect($everything)->toBe('Chen Wei Tan|Chen|Sam Lee|order_one|TRK1|arrived|Driver has arrived|Waiting.')
        ->and(Action::render('{ Driver.First_Name } is here', $order))->toBe('Chen is here')
        ->and(Action::render('Hello {unknown.thing}  there {activity.status}', $order))->toBe('Hello there')
        ->and(Action::render(null, $order))->toBe('')
        ->and(Action::render('', $order))->toBe('')
        ->and(Action::render('{driver.first_name} accepted {order.id} for {customer.name}{order.tracking_number}', $nobody))->toBe('accepted order_two for')
        ->and(Action::driverName($nobody))->toBeNull();

    // Without the relation loaded and no driver assigned, nothing is looked up.
    $unassigned = new Order();
    $unassigned->setRawAttributes(['driver_assigned_uuid' => null], true);
    expect(Action::driverName($unassigned))->toBeNull();
});
