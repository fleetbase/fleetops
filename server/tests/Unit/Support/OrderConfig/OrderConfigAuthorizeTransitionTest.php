<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;

/**
 * OrderConfig::authorizeTransition(), the one check every path that moves an order runs: no more
 * activities after a terminal one, strict transitions, configs that are never dispatched, and
 * proof of delivery.
 */
function orderConfigTransitionOrder(string $status, array $attributes = []): Order
{
    $order = new Order();
    $order->setRawAttributes(array_merge(['uuid' => 'order-1', 'status' => $status], $attributes), true);

    return $order;
}

function orderConfigTransitionConfig(array $meta = [], ?array $flow = null): OrderConfig
{
    return new OrderConfig(['flow' => $flow ?? OrderConfigTemplates::attributes('transport')['flow'], 'meta' => $meta]);
}

test('a finished order takes no more activities, unless an operator corrects it', function () {
    $config   = orderConfigTransitionConfig();
    $activity = new Activity(['code' => 'started', 'status' => 'Order Started']);

    [$kept, $refused] = $config->authorizeTransition(orderConfigTransitionOrder('completed'), $activity);
    [, $canceled]     = $config->authorizeTransition(orderConfigTransitionOrder('canceled'), $activity);
    [, $corrected]    = $config->authorizeTransition(orderConfigTransitionOrder('completed'), $activity, null, false, true);
    [, $live]         = $config->authorizeTransition(orderConfigTransitionOrder('dispatched'), $activity);

    expect($refused)->toBe('This order has already finished.')
        ->and($kept)->toBe($activity)
        ->and($canceled)->toBe('This order has already finished.')
        ->and($corrected)->toBeNull()
        ->and($live)->toBeNull();
});

test('a config\'s own terminal activities end its orders too', function () {
    $passenger = new OrderConfig(OrderConfigTemplates::attributes('passenger'));

    [, $noShow] = $passenger->authorizeTransition(orderConfigTransitionOrder('no_show'), new Activity(['code' => 'in_progress']));
    [, $fixed]  = $passenger->authorizeTransition(orderConfigTransitionOrder('no_show'), new Activity(['code' => 'in_progress']), null, false, true);

    expect($noShow)->toBe('This order has already finished.')
        // Correcting a finished order still follows strict transitions: nothing follows no_show.
        ->and($fixed)->toBe('This activity is not a permitted next step for the order.');
});

test('strict transitions use the config\'s own activity and refuse anything that is not a next step', function () {
    $passenger = new OrderConfig(OrderConfigTemplates::attributes('passenger'));
    $sent      = new Activity(['code' => 'arrived', 'status' => 'Made up by the client', 'details' => 'Ignored', 'require_pod' => false]);

    [$applied, $allowed] = $passenger->authorizeTransition(orderConfigTransitionOrder('started'), $sent);
    [, $skipped]         = $passenger->authorizeTransition(orderConfigTransitionOrder('started'), new Activity(['code' => 'completed']));
    [, $unknown]         = $passenger->authorizeTransition(orderConfigTransitionOrder('started'), new Activity(['code' => 'teleported']));
    [, $noCode]          = $passenger->authorizeTransition(orderConfigTransitionOrder('started'), new Activity(['status' => 'Something']));

    // Without strict transitions any activity is applied as sent.
    $loose                    = orderConfigTransitionConfig();
    $free                     = new Activity(['code' => 'teleported', 'status' => 'Anything']);
    [$looseApplied, $looseOk] = $loose->authorizeTransition(orderConfigTransitionOrder('created'), $free);

    expect($allowed)->toBeNull()
        ->and($applied)->not->toBe($sent)
        ->and($applied->get('status'))->toBe('Driver has arrived')
        ->and($applied->get('details'))->toBe('The driver is waiting at the pickup.')
        ->and($skipped)->toBe('This activity is not a permitted next step for the order.')
        ->and($unknown)->toBe('This activity is not a permitted next step for the order.')
        ->and($noCode)->toBe('This activity is not a permitted next step for the order.')
        ->and($looseOk)->toBeNull()
        ->and($looseApplied)->toBe($free);
});

test('a config whose lifecycle turns dispatch off refuses the dispatched activity', function () {
    $dispatched = new Activity(['code' => 'dispatched']);

    [, $off]      = orderConfigTransitionConfig(['lifecycle' => ['dispatch' => false]])->authorizeTransition(orderConfigTransitionOrder('created'), $dispatched);
    [, $offText]  = orderConfigTransitionConfig(['lifecycle' => ['dispatch' => 'false']])->authorizeTransition(orderConfigTransitionOrder('created'), $dispatched);
    [, $on]       = orderConfigTransitionConfig(['lifecycle' => ['dispatch' => true]])->authorizeTransition(orderConfigTransitionOrder('created'), $dispatched);
    [, $default]  = orderConfigTransitionConfig()->authorizeTransition(orderConfigTransitionOrder('created'), $dispatched);
    [, $started]  = orderConfigTransitionConfig(['lifecycle' => ['dispatch' => false]])->authorizeTransition(orderConfigTransitionOrder('created'), new Activity(['code' => 'started']));

    expect($off)->toBe('Orders of this type are not dispatched.')
        ->and($offText)->toBe('Orders of this type are not dispatched.')
        ->and($on)->toBeNull()
        ->and($default)->toBeNull()
        ->and($started)->toBeNull();
});

test('an activity that needs proof of delivery needs proof, unless it is bypassed', function () {
    $config    = orderConfigTransitionConfig();
    $needsPod  = new Activity(['code' => 'dropped', 'require_pod' => true]);
    $completes = new Activity(['code' => 'completed', 'complete' => true]);
    $refusal   = 'Proof of delivery is required for this activity or must be explicitly bypassed.';

    [, $withoutProof] = $config->authorizeTransition(orderConfigTransitionOrder('started'), $needsPod);
    [, $withProof]    = $config->authorizeTransition(orderConfigTransitionOrder('started'), $needsPod, 'proof-1');
    [, $bypassed]     = $config->authorizeTransition(orderConfigTransitionOrder('started'), $needsPod, null, true);
    [, $podOrder]     = $config->authorizeTransition(orderConfigTransitionOrder('started', ['pod_required' => 1]), $completes);
    [, $plainOrder]   = $config->authorizeTransition(orderConfigTransitionOrder('started', ['pod_required' => 0]), $completes);

    expect($withoutProof)->toBe($refusal)
        ->and($withProof)->toBeNull()
        ->and($bypassed)->toBeNull()
        ->and($podOrder)->toBe($refusal)
        ->and($plainOrder)->toBeNull();
});
