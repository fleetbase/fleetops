<?php

use Fleetbase\FleetOps\Models\OrderConfig;

/**
 * Opt-in configured lifecycles (`meta.lifecycle`): a config can define its own initial, completed,
 * canceled and terminal activities, disable dispatch and require configured transitions, without
 * changing the default dispatch lifecycle of configs that do not opt in.
 */
function lifecycleActivity(string $code, array $children = [], bool $complete = false): array
{
    return ['key' => $code, 'code' => $code, 'status' => ucfirst(str_replace('_', ' ', $code)), 'details' => '', 'activities' => $children, 'logic' => [], 'events' => [], 'complete' => $complete];
}

function customLifecycleConfig(array $lifecycle = []): OrderConfig
{
    $config = new OrderConfig();
    $config->setRawAttributes([
        'flow' => json_encode([
            'requested'   => lifecycleActivity('requested', ['assigned', 'cancelled']),
            'assigned'    => lifecycleActivity('assigned', ['handed_over', 'cancelled']),
            'handed_over' => lifecycleActivity('handed_over', ['active']),
            'active'      => lifecycleActivity('active', ['extended', 'returned']),
            'extended'    => lifecycleActivity('extended', ['active']),
            'returned'    => lifecycleActivity('returned', ['completed']),
            'completed'   => lifecycleActivity('completed', [], true),
            'cancelled'   => lifecycleActivity('cancelled'),
        ]),
        'meta' => json_encode(['lifecycle' => array_replace([
            'initial'            => 'requested',
            'completed'          => 'completed',
            'canceled'           => 'cancelled',
            'terminal'           => ['completed', 'cancelled'],
            'dispatch'           => false,
            'strict_transitions' => true,
        ], $lifecycle)]),
    ]);

    return $config;
}

function defaultLifecycleConfig(): OrderConfig
{
    $config = new OrderConfig();
    $config->setRawAttributes([
        'flow' => json_encode([
            'created'    => lifecycleActivity('created', ['dispatched']),
            'dispatched' => lifecycleActivity('dispatched', ['started']),
            'started'    => lifecycleActivity('started', ['completed']),
            'completed'  => lifecycleActivity('completed', [], true),
        ]),
        'meta' => json_encode([]),
    ]);

    return $config;
}

test('configs without meta.lifecycle keep the default dispatch semantics', function () {
    $config = defaultLifecycleConfig();

    expect($config->hasConfiguredLifecycle())->toBeFalse()
        ->and($config->getInitialStatusCode())->toBe('created')
        ->and($config->allowsDispatch())->toBeTrue()
        ->and($config->hasStrictTransitions())->toBeFalse()
        ->and($config->getTerminalActivityCodes())->toBe(['completed', 'canceled'])
        ->and($config->getCanceledActivity()->code)->toBe('canceled')
        ->and($config->getInitialActivity()->code)->toBe('created');
});

test('a configured lifecycle names its own initial, cancel and completion activities', function () {
    $config = customLifecycleConfig();

    expect($config->hasConfiguredLifecycle())->toBeTrue()
        ->and($config->getInitialStatusCode())->toBe('requested')
        ->and($config->getInitialActivity()->code)->toBe('requested')
        ->and($config->getCanceledActivity()->code)->toBe('cancelled')
        ->and($config->getCompletedActivity()->code)->toBe('completed')
        ->and($config->allowsDispatch())->toBeFalse()
        ->and($config->hasStrictTransitions())->toBeTrue()
        ->and($config->isTerminalActivityCode('cancelled'))->toBeTrue()
        ->and($config->isTerminalActivityCode('canceled'))->toBeFalse();
});

test('transitions follow only the configured graph', function () {
    $config = customLifecycleConfig();

    expect($config->canTransition('requested', 'assigned'))->toBeTrue()
        ->and($config->canTransition('handed_over', 'active'))->toBeTrue()
        ->and($config->canTransition('active', 'extended'))->toBeTrue()
        ->and($config->canTransition('extended', 'active'))->toBeTrue()
        ->and($config->canTransition('returned', 'completed'))->toBeTrue()
        // skipped or foreign steps
        ->and($config->canTransition('requested', 'handed_over'))->toBeFalse()
        ->and($config->canTransition('requested', 'dispatched'))->toBeFalse()
        ->and($config->canTransition('active', 'cancelled'))->toBeFalse()
        // terminal and unknown sources
        ->and($config->canTransition('completed', 'requested'))->toBeFalse()
        ->and($config->canTransition('cancelled', 'assigned'))->toBeFalse()
        ->and($config->canTransition(null, 'requested'))->toBeFalse()
        ->and($config->canTransition('nope', 'assigned'))->toBeFalse();
});

test('a configuration-only change (added activity) is honoured without code changes', function () {
    $config = customLifecycleConfig();
    $flow   = json_decode($config->getAttributes()['flow'], true);
    $flow['active']['activities'][] = 'inspection';
    $flow['inspection']             = lifecycleActivity('inspection', ['returned']);
    $config->setRawAttributes(['flow' => json_encode($flow), 'meta' => $config->getAttributes()['meta']]);

    expect($config->canTransition('active', 'inspection'))->toBeTrue()
        ->and($config->canTransition('inspection', 'returned'))->toBeTrue()
        ->and($config->canTransition('inspection', 'completed'))->toBeFalse();
});

test('dispatch can be disabled with string or numeric falsy values', function () {
    foreach ([false, 'false', 0, '0'] as $value) {
        expect(customLifecycleConfig(['dispatch' => $value])->allowsDispatch())->toBeFalse();
    }
    expect(customLifecycleConfig(['dispatch' => true])->allowsDispatch())->toBeTrue();
});
