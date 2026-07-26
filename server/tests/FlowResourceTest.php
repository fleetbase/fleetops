<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Flow\Condition;
use Fleetbase\FleetOps\Flow\Event;
use Fleetbase\FleetOps\Flow\Flow;
use Fleetbase\FleetOps\Flow\FlowResource;
use Fleetbase\FleetOps\Flow\Logic;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;

class FlowTestOrder extends Order
{
    public array $dynamicValues          = [];
    public array $completedActivityCodes = [];

    public function resolveDynamicValue(string $value)
    {
        return $this->dynamicValues[$value] ?? $value;
    }

    public function hasCompletedActivity(Activity $activity): bool
    {
        return in_array($activity->code, $this->completedActivityCodes, true);
    }
}

function flowTestOrder(array $dynamicValues = [], array $completedActivityCodes = []): FlowTestOrder
{
    $order                         = new FlowTestOrder();
    $order->dynamicValues          = $dynamicValues;
    $order->completedActivityCodes = $completedActivityCodes;

    return $order;
}

function flowFixture(): array
{
    return [
        'activities' => [
            [
                'code'       => 'created',
                'activities' => ['ready'],
            ],
            [
                'code'       => 'ready',
                'complete'   => false,
                'events'     => ['order_ready'],
                'activities' => ['completed'],
                'logic'      => [
                    [
                        'type'       => 'and',
                        'conditions' => [
                            ['field' => 'status', 'operator' => 'equal', 'value' => 'ready'],
                        ],
                    ],
                ],
            ],
            [
                'code'       => 'completed',
                'complete'   => true,
                'activities' => [],
                'logic'      => [
                    [
                        'type'       => 'if',
                        'conditions' => [
                            ['field' => 'status', 'operator' => 'equal', 'value' => 'completed'],
                        ],
                    ],
                ],
            ],
        ],
        'created' => [
            'code'       => 'created',
            'activities' => ['ready'],
        ],
        'ready' => [
            'code'       => 'ready',
            'complete'   => false,
            'events'     => ['order_ready'],
            'activities' => ['completed'],
            'logic'      => [
                [
                    'type'       => 'and',
                    'conditions' => [
                        ['field' => 'status', 'operator' => 'equal', 'value' => 'ready'],
                    ],
                ],
            ],
        ],
        'completed' => [
            'code'       => 'completed',
            'complete'   => true,
            'activities' => [],
            'logic'      => [
                [
                    'type'       => 'if',
                    'conditions' => [
                        ['field' => 'status', 'operator' => 'equal', 'value' => 'completed'],
                    ],
                ],
            ],
        ],
    ];
}

test('flow resources expose mutable attributes and json serialization', function () {
    $resource = new FlowResource(['code' => 'created']);
    $returned = $resource->set('status', 'Created');

    expect($returned)->toBe($resource)
        ->and($resource->code)->toBe('created')
        ->and(isset($resource->status))->toBeTrue()
        ->and($resource->get('missing', 'fallback'))->toBe('fallback')
        ->and($resource->serialize())->toBe(['code' => 'created', 'status' => 'Created'])
        ->and($resource->toArray())->toBe($resource->serialize())
        ->and(json_decode($resource->toJson(), true))->toBe($resource->serialize())
        ->and($resource->jsonSerialize())->toBe($resource->serialize());
});

test('flow locates keyed activities and iterates configured activity list', function () {
    $flow = new Flow(flowFixture());

    expect(iterator_to_array($flow))->toHaveCount(3)
        ->and($flow->getActivity('ready'))->toBeInstanceOf(Activity::class)
        ->and($flow->getActivity('ready')?->code)->toBe('ready')
        ->and($flow->getActivity('missing'))->toBeNull()
        ->and(Flow::isActivity($flow->getActivity('ready')))->toBeTrue()
        ->and(Flow::isActivity(new stdClass()))->toBeFalse();
});

test('flow conditions evaluate comparison operators and reject unknown operators', function () {
    $order = flowTestOrder([
        'status'   => 'Ready',
        'priority' => 3,
        'notes'    => 'deliver fragile parcel',
    ]);

    expect((new Condition(['field' => 'status', 'operator' => 'equal', 'value' => 'ready']))->eval($order))->toBeTrue()
        ->and((new Condition(['field' => 'status', 'operator' => 'notEqual', 'value' => 'completed']))->eval($order))->toBeTrue()
        ->and((new Condition(['field' => 'priority', 'operator' => 'greaterThan', 'value' => 2]))->eval($order))->toBeTrue()
        ->and((new Condition(['field' => 'priority', 'operator' => 'lessThanOrEqual', 'value' => 3]))->eval($order))->toBeTrue()
        ->and((new Condition(['field' => 'notes', 'operator' => 'contains', 'value' => 'deliver fragile parcel today']))->eval($order))->toBeTrue()
        ->and((new Condition(['field' => 'notes', 'operator' => 'beginsWith', 'value' => 'deliver fragile parcel']))->eval($order))->toBeTrue()
        ->and((new Condition(['field' => 'notes', 'operator' => 'endsWith', 'value' => 'today deliver fragile parcel']))->eval($order))->toBeTrue();

    expect(fn () => (new Condition(['field' => 'status', 'operator' => 'between', 'value' => []]))->eval($order))
        ->toThrow(Exception::class, 'Unknown operator: between');
});

test('flow logic combines conditions with and or not and if semantics', function () {
    $readyOrder = flowTestOrder(['status' => 'ready', 'priority' => 5]);
    $heldOrder  = flowTestOrder(['status' => 'held', 'priority' => 1]);

    expect((new Logic([
        'type'       => 'and',
        'conditions' => [
            ['field' => 'status', 'operator' => 'equal', 'value' => 'ready'],
            ['field' => 'priority', 'operator' => 'greaterThanOrEqual', 'value' => 5],
        ],
    ]))->passes($readyOrder))->toBeTrue()
        ->and((new Logic([
            'type'       => 'or',
            'conditions' => [
                ['field' => 'status', 'operator' => 'equal', 'value' => 'ready'],
                ['field' => 'priority', 'operator' => 'greaterThan', 'value' => 5],
            ],
        ]))->passes($readyOrder))->toBeTrue()
        ->and((new Logic([
            'type'       => 'not',
            'conditions' => [
                ['field' => 'status', 'operator' => 'equal', 'value' => 'ready'],
            ],
        ]))->passes($heldOrder))->toBeTrue()
        ->and((new Logic([
            'type'       => 'if',
            'conditions' => [
                ['field' => 'status', 'operator' => 'equal', 'value' => 'ready'],
            ],
        ]))->passes($readyOrder))->toBeTrue();

    expect(fn () => (new Logic(['type' => 'xor']))->passes($readyOrder))
        ->toThrow(Exception::class, 'Unknown logic type: xor');
});

test('activities expose events child traversal next previous and completion state', function () {
    $flow     = flowFixture();
    $activity = new Activity($flow['ready'], $flow);

    expect($activity->logic)->toHaveCount(1)
        ->and($activity->events[0])->toBeInstanceOf(Event::class)
        ->and($activity->children())->toHaveCount(1)
        ->and($activity->hasChildActivity('completed'))->toBeTrue()
        ->and($activity->hasChildActivity('missing'))->toBeFalse()
        ->and($activity->is('ready'))->toBeTrue()
        ->and($activity->complete())->toBeFalse()
        ->and($activity->completesOrder())->toBeFalse()
        ->and($activity->isCompleted(flowTestOrder([], ['ready'])))->toBeTrue()
        ->and($activity->getNext(flowTestOrder(['status' => 'completed']))->first()?->code)->toBe('completed')
        ->and($activity->getNext(flowTestOrder(['status' => 'ready'])))->toHaveCount(0)
        ->and($activity->getPrevious()->first()?->code)->toBe('created');
});

test('flow events resolve names mutate context and no-op unresolved fires', function () {
    $order    = flowTestOrder();
    $activity = new Activity(['code' => 'ready'], flowFixture());
    $waypoint = new Waypoint();
    $event    = new Event('order_ready');

    expect($event->resolve())->toBe('\\Fleetbase\\FleetOps\\Events\\OrderReady')
        ->and($event->setOrder($order))->toBe($event)
        ->and($event->setActivity($activity))->toBe($event)
        ->and($event->setWaypoint($waypoint))->toBe($event)
        ->and($event->order)->toBe($order)
        ->and($event->activity)->toBe($activity)
        ->and($event->waypoint)->toBe($waypoint)
        ->and((new Event('missing_event_name'))->resolve())->toBeNull();

    $unresolved = new Event('missing_event_name');
    $unresolved->fire($order, $activity, $waypoint);

    expect($unresolved->order)->toBe($order)
        ->and($unresolved->activity)->toBe($activity)
        ->and($unresolved->waypoint)->toBe($waypoint);
});
