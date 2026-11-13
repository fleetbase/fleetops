<?php

namespace Fleetbase\FleetOps\Support\OrderConfig;

use Fleetbase\FleetOps\Contracts\OrderConfigPolicy;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\AdhocDispatchPolicy;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\CustomerCancellationPolicy;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\CustomerInteractionsPolicy;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\CustomerOrderingPolicy;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\DriverMatchingPolicy;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\PricingPolicy;

/**
 * The registry of order config policies. Extensions add their own with `register()`.
 */
class OrderConfigPolicies
{
    /**
     * @var array<string, class-string<OrderConfigPolicy>>
     */
    protected static array $policies = [
        'dispatch.adhoc'        => AdhocDispatchPolicy::class,
        'dispatch.matching'     => DriverMatchingPolicy::class,
        'customer.ordering'     => CustomerOrderingPolicy::class,
        'customer.cancellation' => CustomerCancellationPolicy::class,
        'customer.interactions' => CustomerInteractionsPolicy::class,
        'pricing'               => PricingPolicy::class,
    ];

    /**
     * Add a policy, or replace one, under its key.
     *
     * @param class-string<OrderConfigPolicy> $class
     */
    public static function register(string $class): void
    {
        if (!is_subclass_of($class, OrderConfigPolicy::class)) {
            throw new \InvalidArgumentException($class . ' does not implement ' . OrderConfigPolicy::class . '.');
        }

        static::$policies[app($class)->key()] = $class;
    }

    /**
     * @return array<string, OrderConfigPolicy>
     */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), static::$policies);
    }

    public static function get(string $key): ?OrderConfigPolicy
    {
        return isset(static::$policies[$key]) ? app(static::$policies[$key]) : null;
    }

    /**
     * Every policy's defaults, nested by key.
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (static::all() as $key => $policy) {
            data_set($defaults, $key, $policy->defaults());
        }

        return $defaults;
    }

    /**
     * The stored policies with every missing setting filled in from its default. Settings of
     * policies that aren't registered are kept as stored.
     */
    public static function resolve(?array $stored): array
    {
        $resolved = $stored ?? [];
        foreach (static::all() as $key => $policy) {
            $settings = data_get($resolved, $key);
            data_set($resolved, $key, array_replace($policy->defaults(), is_array($settings) ? $settings : []));
        }

        return $resolved;
    }

    /**
     * Validation rules for a request carrying `policies`.
     */
    public static function rules(string $prefix = 'policies'): array
    {
        $rules = [$prefix => ['nullable', 'array']];
        foreach (static::all() as $key => $policy) {
            $rules[$prefix . '.' . $key] = ['nullable', 'array'];
            foreach ($policy->rules() as $setting => $settingRules) {
                $rules[$prefix . '.' . $key . '.' . $setting] = $settingRules;
            }
        }

        return $rules;
    }

    /**
     * Every policy, for people configuring them, with each setting's type (`boolean`, `number`,
     * `text`, `list` or `choice`) and choices.
     *
     * @return array<int, array{key: string, label: string, description: string, defaults: array, settings: array}>
     */
    public static function describe(): array
    {
        return array_values(array_map(function (OrderConfigPolicy $policy) {
            $rules    = $policy->rules();
            $choices  = $policy->choices();
            $settings = [];
            foreach ($policy->defaults() as $name => $default) {
                $settingRules = (array) ($rules[$name] ?? []);
                $type         = match (true) {
                    isset($choices[$name])                                                               => 'choice',
                    is_bool($default) || in_array('boolean', $settingRules, true)                        => 'boolean',
                    is_array($default) || in_array('array', $settingRules, true)                         => 'list',
                    in_array('integer', $settingRules, true) || in_array('numeric', $settingRules, true) => 'number',
                    default                                                                              => 'text',
                };
                $settings[] = ['name' => $name, 'type' => $type, 'default' => $default, 'choices' => $choices[$name] ?? null];
            }

            return [
                'key'         => $policy->key(),
                'label'       => $policy->label(),
                'description' => $policy->description(),
                'defaults'    => $policy->defaults(),
                'settings'    => $settings,
            ];
        }, static::all()));
    }
}
