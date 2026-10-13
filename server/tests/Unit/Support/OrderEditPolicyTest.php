<?php

use Fleetbase\FleetOps\Exceptions\OrderEditLockedException;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderEditPolicy;

/**
 * Covers the order edit lock policy: the stage each status falls into, the fields
 * each stage locks or restricts, and the refusal raised for a locked edit.
 */
function fleetopsEditPolicyOrder(string $status, array $attributes = [], ?OrderConfig $config = null): Order
{
    $order = new Order();
    $order->setRawAttributes(array_merge(['uuid' => 'order-uuid', 'public_id' => 'order_policy', 'status' => $status], $attributes), true);
    $order->setRelation('orderConfig', $config);

    return $order;
}

test('open statuses leave every field editable', function () {
    foreach (['created', 'pending', 'accepted', 'confirmed', 'preparing'] as $status) {
        $policy = OrderEditPolicy::for(fleetopsEditPolicyOrder($status));

        expect($policy->stage())->toBe(OrderEditPolicy::STAGE_OPEN)
            ->and($policy->locked())->toBe([])
            ->and($policy->restrictions())->toBe([])
            ->and($policy->check('items', ['adds' => true]))->toBeNull()
            ->and($policy->check('schedule', ['from' => '2026-10-13 12:00:00', 'to' => '2026-10-14 12:00:00']))->toBeNull();
    }

    expect(OrderEditPolicy::for(fleetopsEditPolicyOrder('preparing'))->flagsKitchenChanges())->toBeTrue()
        ->and(OrderEditPolicy::for(fleetopsEditPolicyOrder('created'))->flagsKitchenChanges())->toBeFalse();
});

test('ready orders only allow removing items and moving the schedule earlier', function () {
    $policy = OrderEditPolicy::for(fleetopsEditPolicyOrder('pickup_ready'));

    expect($policy->stage())->toBe(OrderEditPolicy::STAGE_READY)
        ->and($policy->locked())->toBe([])
        ->and($policy->restrictions())->toBe(['items' => 'removal_only', 'schedule' => 'earlier_only'])
        ->and($policy->check('dropoff'))->toBeNull()
        ->and($policy->check('contact'))->toBeNull()
        ->and($policy->check('items', ['adds' => false]))->toBeNull()
        ->and($policy->check('items', ['adds' => true]))->toBe('Once the order is ready, items can only be removed.')
        ->and($policy->check('schedule', ['from' => '2026-10-13 12:00:00', 'to' => '2026-10-13 11:30:00']))->toBeNull()
        ->and($policy->check('schedule', ['from' => '2026-10-13 12:00:00', 'to' => '2026-10-13 12:30:00']))->toBe('Once the order is ready, the schedule can only be moved earlier.')
        ->and($policy->check('schedule', ['from' => null, 'to' => '2026-10-13 12:30:00']))->not->toBeNull();
});

test('orders a driver has keep route contact and notes editable but lock items and schedule', function () {
    $cases = [
        fleetopsEditPolicyOrder('dispatched'),
        fleetopsEditPolicyOrder('driver_enroute'),
        fleetopsEditPolicyOrder('enroute'),
        fleetopsEditPolicyOrder('created', ['started' => true]),
        fleetopsEditPolicyOrder('at_customs', ['dispatched' => true]),
    ];

    foreach ($cases as $order) {
        $policy = OrderEditPolicy::for($order);

        expect($policy->stage())->toBe(OrderEditPolicy::STAGE_IN_TRANSIT)
            ->and(array_keys($policy->locked()))->toBe(['items', 'schedule'])
            ->and($policy->allows('dropoff'))->toBeTrue()
            ->and($policy->allows('waypoints'))->toBeTrue()
            ->and($policy->allows('contact'))->toBeTrue()
            ->and($policy->allows('notes'))->toBeTrue()
            ->and($policy->allows('cod_amount'))->toBeTrue()
            ->and($policy->allows('items'))->toBeFalse();
    }

    // a custom status before dispatch is still open
    expect(OrderEditPolicy::for(fleetopsEditPolicyOrder('awaiting_stock'))->stage())->toBe(OrderEditPolicy::STAGE_OPEN);
});

test('completed canceled and expired orders and the config terminal codes lock everything', function () {
    foreach (['completed', 'canceled', 'expired'] as $status) {
        $policy = OrderEditPolicy::for(fleetopsEditPolicyOrder($status));

        expect($policy->stage())->toBe(OrderEditPolicy::STAGE_TERMINAL)
            ->and($policy->isTerminal())->toBeTrue()
            ->and(array_keys($policy->locked()))->toBe(OrderEditPolicy::FIELDS)
            ->and($policy->check('notes'))->toBe(sprintf('The order is %s and can no longer be changed.', $status));
    }

    $config = new OrderConfig();
    $config->setRawAttributes([
        'uuid' => 'config-uuid',
        'meta' => json_encode(['lifecycle' => ['initial' => 'booked', 'completed' => 'delivered', 'terminal' => ['no_show']]]),
        'flow' => json_encode([
            'booked'    => ['key' => 'booked', 'code' => 'booked', 'status' => 'Booked', 'activities' => ['delivered']],
            'delivered' => ['key' => 'delivered', 'code' => 'delivered', 'status' => 'Delivered', 'complete' => true],
            'no_show'   => ['key' => 'no_show', 'code' => 'no_show', 'status' => 'No show'],
        ]),
    ], true);

    expect(OrderEditPolicy::for(fleetopsEditPolicyOrder('delivered', [], $config))->stage())->toBe(OrderEditPolicy::STAGE_TERMINAL)
        ->and(OrderEditPolicy::for(fleetopsEditPolicyOrder('no_show', [], $config))->stage())->toBe(OrderEditPolicy::STAGE_TERMINAL)
        ->and(OrderEditPolicy::for(fleetopsEditPolicyOrder('booked', [], $config))->stage())->toBe(OrderEditPolicy::STAGE_OPEN);
});

test('asserting a locked edit throws with every refused field and the policy serializes', function () {
    $policy = OrderEditPolicy::for(fleetopsEditPolicyOrder('dispatched'));

    $policy->assert(['dropoff' => true, 'notes' => true]);

    try {
        $policy->assert(['dropoff' => true, 'items' => ['adds' => false], 'schedule' => ['from' => 'a', 'to' => 'b']]);
        $this->fail('Expected the edit to be refused');
    } catch (OrderEditLockedException $exception) {
        expect($exception->locked)->toBe([
            'items'    => 'Items cannot be changed once a driver has the order.',
            'schedule' => 'The schedule cannot be changed once a driver has the order.',
        ])
            ->and($exception->getCode())->toBe(422)
            ->and($exception->getMessage())->toContain('Items cannot be changed')
            ->and($exception->toResponseData())->toBe(['locked' => $exception->locked]);
    }

    $serialized = OrderEditPolicy::for(fleetopsEditPolicyOrder('ready'))->toArray();

    expect($serialized['stage'])->toBe('ready')
        ->and($serialized['status'])->toBe('ready')
        ->and((array) $serialized['locked'])->toBe([])
        ->and((array) $serialized['restrictions'])->toBe(['items' => 'removal_only', 'schedule' => 'earlier_only'])
        ->and(fleetopsEditPolicyOrder('ready')->editPolicy())->toBeInstanceOf(OrderEditPolicy::class);
});
