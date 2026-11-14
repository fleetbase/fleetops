<?php

use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigPolicies;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\FleetOps\Support\OrderConfig\Templates\PassengerTemplate;
use Fleetbase\FleetOps\Support\OrderConfig\Templates\TransportTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The order config templates: the transport template every company gets, unchanged from the
 * default transport config it replaced, and the passenger template, which is ordinary config
 * (strict flow, policies and triggers).
 */
function orderConfigTemplatesActivity(string $code, string $status, string $details, array $next, bool $complete = false): array
{
    return [
        'key'         => $code,
        'code'        => $code,
        'color'       => '#1f2937',
        'logic'       => [],
        'events'      => [],
        'status'      => $status,
        'actions'     => [],
        'details'     => $details,
        'options'     => [],
        'complete'    => $complete,
        'entities'    => [],
        'sequence'    => 0,
        'activities'  => $next,
        'pod_method'  => 'scan',
        'require_pod' => false,
    ];
}

/** The flow without the generated `internalId` of each activity. */
function orderConfigTemplatesFlow(array $flow): array
{
    return array_map(fn ($activity) => Arr::except($activity, 'internalId'), $flow);
}

test('the registry describes its templates and refuses unknown ones', function () {
    expect(OrderConfigTemplates::describe())->toBe([
        ['key' => 'transport', 'name' => 'Transport', 'description' => 'Default order configuration for transport'],
        ['key' => 'passenger', 'name' => 'Passenger', 'description' => (new PassengerTemplate())->description()],
    ])
        ->and(OrderConfigTemplates::has('passenger'))->toBeTrue()
        ->and(OrderConfigTemplates::has('limousine'))->toBeFalse()
        ->and(OrderConfigTemplates::has(null))->toBeFalse()
        ->and(OrderConfigTemplates::get('transport'))->toBeInstanceOf(TransportTemplate::class)
        ->and(OrderConfigTemplates::get('limousine'))->toBeNull()
        ->and(fn () => OrderConfigTemplates::attributes('limousine'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OrderConfigTemplates::register(stdClass::class))->toThrow(InvalidArgumentException::class);
});

test('the transport template is the default transport config it replaced', function () {
    $attributes = OrderConfigTemplates::attributes('transport');

    expect(Arr::except($attributes, 'flow'))->toBe([
        'name'         => 'Transport',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'core_service' => 1,
        'status'       => 'private',
        'version'      => '0.0.1',
        'description'  => 'Default order configuration for transport',
        'tags'         => ['transport', 'delivery'],
        'entities'     => [],
        'meta'         => [],
    ])
        ->and(array_keys($attributes['flow']))->toBe(['created', 'enroute', 'started', 'completed', 'dispatched'])
        ->and(orderConfigTemplatesFlow($attributes['flow']))->toBe([
            'created'    => orderConfigTemplatesActivity('created', 'Order Created', 'New order was created.', ['dispatched']),
            'enroute'    => orderConfigTemplatesActivity('enroute', 'Driver Enroute', 'Driver is en-route.', ['completed']),
            'started'    => orderConfigTemplatesActivity('started', 'Order Started', 'Order has been started', ['enroute']),
            'completed'  => orderConfigTemplatesActivity('completed', 'Order Completed', 'Order has been completed.', [], true),
            'dispatched' => orderConfigTemplatesActivity('dispatched', 'Order Dispatched', 'Order has been dispatched.', ['started']),
        ])
        ->and(collect($attributes['flow'])->every(fn ($activity) => Str::isUuid($activity['internalId'])))->toBeTrue()
        ->and((new TransportTemplate())->customFields())->toBe([]);
});

test('the passenger template is a strict flow ending in completed, canceled or no-show', function () {
    $attributes = OrderConfigTemplates::attributes('passenger');
    $flow       = $attributes['flow'];

    expect(Arr::only($attributes, ['name', 'key', 'namespace', 'core_service', 'status', 'version', 'tags', 'entities']))->toBe([
        'name'         => 'Passenger',
        'key'          => 'passenger',
        'namespace'    => 'system:order-config:passenger',
        'core_service' => 1,
        'status'       => 'private',
        'version'      => '0.0.1',
        'tags'         => ['passenger', 'on-demand'],
        'entities'     => [],
    ])
        ->and($attributes['meta'])->toBe(['lifecycle' => ['initial' => 'created', 'completed' => 'completed', 'canceled' => 'canceled', 'terminal' => ['no_show'], 'strict_transitions' => true]])
        ->and(array_map(fn ($activity) => $activity['activities'], $flow))->toBe([
            'created'     => ['dispatched'],
            'dispatched'  => ['started'],
            'started'     => ['arrived'],
            'arrived'     => ['in_progress', 'no_show'],
            'in_progress' => ['completed'],
            'completed'   => [],
            'no_show'     => [],
        ])
        ->and(array_keys(array_filter($flow, fn ($activity) => $activity['complete'])))->toBe(['completed'])
        ->and(array_map(fn ($activity) => array_column($activity['actions'], 'action'), $flow))->toBe([
            'created'     => [],
            'dispatched'  => [],
            'started'     => ['notify_customer', 'open_chat'],
            'arrived'     => ['notify_customer'],
            'in_progress' => ['notify_customer'],
            'completed'   => ['notify_customer', 'close_chat', 'send_invoice'],
            'no_show'     => ['close_chat'],
        ])
        ->and($flow['started']['actions'][0]['params'])->toBe(['title' => 'Your driver is on the way', 'message' => '{driver.first_name} accepted your trip.']);
});

test('the passenger template\'s policies offer orders one driver at a time, by vehicle type, and price them from the trip', function () {
    $policies = OrderConfigTemplates::attributes('passenger')['policies'];

    expect($policies)->toBe([
        'dispatch' => [
            'adhoc'    => ['customer_orders' => true, 'strategy' => 'sequential', 'offer_timeout' => 20, 'on_exhausted' => 'cancel'],
            'matching' => ['vehicle_type' => true, 'exclude_busy_drivers' => true],
        ],
        'customer' => [
            'ordering'     => ['payment_methods' => ['cash', 'card'], 'require_quote' => true, 'require_settlement' => ['card']],
            'cancellation' => ['enabled' => true, 'allowed_until' => 'in_progress'],
            'interactions' => ['chat' => true, 'rating' => true, 'share_tracking' => true],
        ],
        'pricing' => ['reprice_on_completion' => true, 'metering_starts_at' => 'in_progress'],
    ])
        // Every setting it leaves out takes the policy's default.
        ->and(OrderConfigPolicies::resolve($policies)['dispatch']['adhoc']['radius'])->toBeNull()
        ->and(array_keys(OrderConfigPolicies::resolve($policies)))->toBe(['dispatch', 'customer', 'pricing']);
});

test('the passenger template\'s triggers tell the customer as the driver nears the pickup, when nobody is found, and when canceled', function () {
    $triggers = OrderConfigTemplates::attributes('passenger')['triggers'];

    expect(array_column($triggers, 'event'))->toBe(['driver.approaching', 'dispatch.offer_exhausted', 'order.canceled', 'order.canceled'])
        ->and(array_column($triggers, 'action'))->toBe(['notify_customer', 'notify_customer', 'notify_customer', 'close_chat'])
        ->and($triggers[0]['options'])->toBe(['target' => 'pickup', 'distance' => 400])
        ->and($triggers[0]['when'])->toBe([['type' => 'and', 'conditions' => [['field' => 'status', 'operator' => 'equal', 'value' => 'started']]]])
        ->and($triggers[0]['params'])->toBe(['title' => 'Your driver is almost there', 'message' => '{driver.first_name} is about a minute away.'])
        ->and($triggers[1]['params']['title'])->toBe('No driver found')
        // The cancellation notice is skipped when the customer was already told nobody was found.
        ->and($triggers[2]['when'])->toBe([['type' => 'and', 'conditions' => [['field' => 'dispatch_outcome', 'operator' => 'notEqual', 'value' => 'no_driver_found']]]]);
});

test('the passenger template asks how many people are travelling', function () {
    expect((new PassengerTemplate())->customFields())->toBe([[
        'name'             => 'passengers',
        'label'            => 'Passengers',
        'type'             => 'input',
        'component'        => 'input',
        'default_value'    => '1',
        'required'         => false,
        'editable'         => true,
        'validation_rules' => ['nullable', 'integer', 'min:1', 'max:50'],
        'help_text'        => 'How many people are travelling.',
        'order'            => 0,
    ]]);
});

/** A template an extension might add. */
class OrderConfigTemplatesFreightTemplate extends Fleetbase\FleetOps\Support\OrderConfig\Templates\Template
{
    protected string $key         = 'freight';
    protected string $name        = 'Freight';
    protected string $description = 'Pallets, picked up and delivered.';

    public function attributes(): array
    {
        return ['tags' => ['freight'], 'flow' => ['created' => static::activity('created', 'Order Created', 'New order was created.')]];
    }
}

test('extensions register their own templates by key, and an unknown template makes no config', function () {
    $registry = new ReflectionProperty(OrderConfigTemplates::class, 'templates');
    $before   = $registry->getValue();

    try {
        OrderConfigTemplates::register(OrderConfigTemplatesFreightTemplate::class);

        expect(OrderConfigTemplates::has('freight'))->toBeTrue()
            ->and(OrderConfigTemplates::get('freight'))->toBeInstanceOf(OrderConfigTemplatesFreightTemplate::class)
            ->and(array_column(OrderConfigTemplates::describe(), 'key'))->toBe(['transport', 'passenger', 'freight'])
            ->and(Arr::only(OrderConfigTemplates::attributes('freight'), ['name', 'key', 'namespace', 'tags']))->toBe([
                'name'      => 'Freight',
                'key'       => 'freight',
                'namespace' => 'system:order-config:freight',
                'tags'      => ['freight'],
            ]);
    } finally {
        $registry->setValue(null, $before);
    }

    // Refused before any config is looked up or made.
    expect(fn () => OrderConfigTemplates::create('limousine', new Fleetbase\Models\Company()))->toThrow(InvalidArgumentException::class, 'No order config template named limousine.')
        ->and(OrderConfigTemplates::has('freight'))->toBeFalse();
});
