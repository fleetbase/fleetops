<?php

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigPolicies;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\Policy;

/**
 * The order config policy registry: defaults, filling in stored policies, validation rules, the
 * description people configure them from, and how a config keeps its stored policies valid.
 */
// The validation package isn't installed for these tests; the adhoc policy's `Rule::in()` only
// needs to name its values.
if (!class_exists('Illuminate\Validation\Rule')) {
    eval('namespace Illuminate\Validation; class Rule { public static function in($values) { return new class($values) { public function __construct(public $values) { } public function __toString(): string { return "in:" . implode(",", $this->values); } }; } }');
}

class OrderConfigPoliciesTestInvoicingPolicy extends Policy
{
    protected string $key         = 'billing.invoicing';
    protected string $label       = 'Invoicing';
    protected string $description = 'Send an invoice when the order completes.';
    protected array $defaults     = ['auto_send' => false, 'note' => null, 'due_days' => 14];
    protected array $rules        = ['note' => ['nullable', 'string'], 'due_days' => ['nullable', 'integer']];
}

function orderConfigPoliciesSetting(string $key, string $name): ?array
{
    $policy = collect(OrderConfigPolicies::describe())->firstWhere('key', $key);

    return collect($policy['settings'] ?? [])->firstWhere('name', $name);
}

test('every policy has defaults: nothing a config does not ask for is switched on', function () {
    expect(OrderConfigPolicies::defaults())->toBe([
        'dispatch' => [
            'adhoc'    => ['customer_orders' => false, 'strategy' => null, 'offer_timeout' => null, 'radius' => null, 'on_exhausted' => 'keep_open'],
            'matching' => ['vehicle_type' => false, 'exclude_busy_drivers' => false],
        ],
        'customer' => [
            'ordering'     => ['payment_methods' => [], 'require_quote' => false, 'require_settlement' => []],
            'cancellation' => ['enabled' => true, 'allowed_until' => null],
            'interactions' => ['chat' => false, 'rating' => false, 'share_tracking' => false],
        ],
        'pricing' => ['reprice_on_completion' => false, 'metering_starts_at' => null],
    ])
        ->and(array_keys(OrderConfigPolicies::all()))->toBe(['dispatch.adhoc', 'dispatch.matching', 'customer.ordering', 'customer.cancellation', 'customer.interactions', 'pricing'])
        ->and(OrderConfigPolicies::get('pricing')->label())->toBe('Pricing')
        ->and(OrderConfigPolicies::get('nope'))->toBeNull();
});

test('resolving stored policies fills in each missing setting and keeps policies nobody registered', function () {
    $resolved = OrderConfigPolicies::resolve([
        'dispatch' => ['adhoc' => ['strategy' => 'broadcast', 'on_exhausted' => 'cancel']],
        'pricing'  => 'not a policy',
        'billing'  => ['invoicing' => ['auto_send' => true]],
    ]);

    expect(OrderConfigPolicies::resolve(null))->toBe(OrderConfigPolicies::defaults())
        ->and(OrderConfigPolicies::resolve([]))->toBe(OrderConfigPolicies::defaults())
        ->and($resolved['dispatch']['adhoc'])->toBe(['customer_orders' => false, 'strategy' => 'broadcast', 'offer_timeout' => null, 'radius' => null, 'on_exhausted' => 'cancel'])
        ->and($resolved['dispatch']['matching'])->toBe(['vehicle_type' => false, 'exclude_busy_drivers' => false])
        ->and($resolved['pricing'])->toBe(['reprice_on_completion' => false, 'metering_starts_at' => null])
        ->and($resolved['billing'])->toBe(['invoicing' => ['auto_send' => true]]);
});

test('validation rules sit under the prefix, one set per policy and per setting', function () {
    $rules  = OrderConfigPolicies::rules();
    $custom = OrderConfigPolicies::rules('config.policies');

    expect($rules['policies'])->toBe(['nullable', 'array'])
        ->and($rules['policies.dispatch.adhoc'])->toBe(['nullable', 'array'])
        ->and($rules['policies.dispatch.adhoc.customer_orders'])->toBe(['nullable', 'boolean'])
        ->and($rules['policies.dispatch.adhoc.offer_timeout'])->toBe(['nullable', 'integer', 'min:10', 'max:120'])
        ->and($rules['policies.customer.ordering.payment_methods.*'])->toBe(['string', 'max:32'])
        ->and((string) $rules['policies.dispatch.adhoc.on_exhausted'][1])->toBe('in:keep_open,cancel')
        ->and((string) $rules['policies.dispatch.adhoc.strategy'][2])->toContain('sequential', 'broadcast')
        ->and($rules['policies.pricing.metering_starts_at'])->toBe(['nullable', 'string', 'max:64'])
        ->and(array_keys($custom))->toContain('config.policies', 'config.policies.customer.interactions.chat')
        ->and(array_filter(array_keys($custom), fn ($key) => !str_starts_with($key, 'config.policies')))->toBe([]);
});

test('policies describe each setting\'s type, default and choices for people configuring them', function () {
    $adhoc = collect(OrderConfigPolicies::describe())->firstWhere('key', 'dispatch.adhoc');

    expect($adhoc['label'])->toBe('Adhoc dispatch')
        ->and($adhoc['defaults']['on_exhausted'])->toBe('keep_open')
        ->and(array_column($adhoc['settings'], 'type', 'name'))->toBe([
            'customer_orders' => 'boolean',
            'strategy'        => 'choice',
            'offer_timeout'   => 'number',
            'radius'          => 'number',
            'on_exhausted'    => 'choice',
        ])
        ->and(array_column(orderConfigPoliciesSetting('dispatch.adhoc', 'strategy')['choices'], 'value'))->toContain('sequential', 'broadcast')
        ->and(orderConfigPoliciesSetting('dispatch.adhoc', 'on_exhausted')['choices'])->toBe([
            ['value' => 'keep_open', 'label' => 'Keep the order open for the operator'],
            ['value' => 'cancel', 'label' => 'Cancel the order'],
        ])
        ->and(orderConfigPoliciesSetting('dispatch.adhoc', 'customer_orders'))->toBe(['name' => 'customer_orders', 'type' => 'boolean', 'default' => false, 'choices' => null])
        ->and(orderConfigPoliciesSetting('customer.ordering', 'payment_methods')['type'])->toBe('list')
        ->and(orderConfigPoliciesSetting('customer.ordering', 'require_settlement')['type'])->toBe('list')
        // An activity setting is a choice among the config's own activities.
        ->and(orderConfigPoliciesSetting('customer.cancellation', 'allowed_until'))->toBe(['name' => 'allowed_until', 'type' => 'choice', 'default' => null, 'choices' => 'activity'])
        ->and(orderConfigPoliciesSetting('pricing', 'metering_starts_at')['choices'])->toBe('activity');
});

test('a config\'s policy reads one setting or a whole policy, with defaults and a fallback', function () {
    $config = new OrderConfig(['policies' => ['dispatch' => ['adhoc' => ['strategy' => 'broadcast']]]]);
    $empty  = new OrderConfig();

    expect($config->policy('dispatch.adhoc.strategy'))->toBe('broadcast')
        ->and($config->policy('dispatch.adhoc.on_exhausted'))->toBe('keep_open')
        ->and($config->policy('dispatch.matching'))->toBe(['vehicle_type' => false, 'exclude_busy_drivers' => false])
        ->and($config->policy('dispatch.adhoc.offer_timeout', 30))->toBe(30)
        ->and($config->policy('nothing.here', 'fallback'))->toBe('fallback')
        ->and($empty->resolvedPolicies())->toBe(OrderConfigPolicies::defaults())
        ->and($empty->policy('customer.interactions.chat'))->toBeFalse();
});

test('a config drops an adhoc strategy that is not registered and keeps the offer timeout in range', function () {
    $normalized = function (?array $policies) {
        $config           = new OrderConfig();
        $config->policies = $policies;
        $config->normalizePolicies();

        return $config->policies;
    };

    expect($normalized(['dispatch' => ['adhoc' => ['strategy' => 'lottery', 'offer_timeout' => 900]]]))->toBe(['dispatch' => ['adhoc' => ['strategy' => null, 'offer_timeout' => 120]]])
        ->and($normalized(['dispatch' => ['adhoc' => ['strategy' => 'broadcast', 'offer_timeout' => '3']]]))->toBe(['dispatch' => ['adhoc' => ['strategy' => 'broadcast', 'offer_timeout' => 10]]])
        ->and($normalized(['dispatch' => ['adhoc' => ['offer_timeout' => '']]]))->toBe(['dispatch' => ['adhoc' => ['offer_timeout' => '']]])
        ->and($normalized(['pricing' => ['reprice_on_completion' => true]]))->toBe(['pricing' => ['reprice_on_completion' => true]])
        ->and($normalized(null))->toBeNull();
});

test('extensions register their own policies, and only real policies', function () {
    OrderConfigPolicies::register(OrderConfigPoliciesTestInvoicingPolicy::class);

    expect(OrderConfigPolicies::get('billing.invoicing'))->toBeInstanceOf(OrderConfigPoliciesTestInvoicingPolicy::class)
        ->and(OrderConfigPolicies::defaults()['billing']['invoicing'])->toBe(['auto_send' => false, 'note' => null, 'due_days' => 14])
        ->and(OrderConfigPolicies::resolve([])['billing']['invoicing']['due_days'])->toBe(14)
        ->and(OrderConfigPolicies::rules()['policies.billing.invoicing.due_days'])->toBe(['nullable', 'integer'])
        ->and(orderConfigPoliciesSetting('billing.invoicing', 'auto_send')['type'])->toBe('boolean')
        ->and(orderConfigPoliciesSetting('billing.invoicing', 'note')['type'])->toBe('text')
        ->and(orderConfigPoliciesSetting('billing.invoicing', 'due_days')['type'])->toBe('number')
        ->and(fn () => OrderConfigPolicies::register(stdClass::class))->toThrow(InvalidArgumentException::class);
});
