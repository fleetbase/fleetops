<?php

namespace Fleetbase\FleetOps\Support\OrderConfig;

use Fleetbase\FleetOps\Contracts\OrderConfigTemplate;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderConfig\Templates\PassengerTemplate;
use Fleetbase\FleetOps\Support\OrderConfig\Templates\TransportTemplate;
use Fleetbase\Models\Company;
use Illuminate\Support\Str;

/**
 * The registry of order config templates, and making a company's config from one. Extensions
 * add their own with `register()`.
 */
class OrderConfigTemplates
{
    /**
     * @var array<string, class-string<OrderConfigTemplate>>
     */
    protected static array $templates = [
        'transport' => TransportTemplate::class,
        'passenger' => PassengerTemplate::class,
    ];

    /**
     * Add a template, or replace one, under its key.
     *
     * @param class-string<OrderConfigTemplate> $class
     */
    public static function register(string $class): void
    {
        if (!is_subclass_of($class, OrderConfigTemplate::class)) {
            throw new \InvalidArgumentException($class . ' does not implement ' . OrderConfigTemplate::class . '.');
        }

        static::$templates[app($class)->key()] = $class;
    }

    public static function has(?string $key): bool
    {
        return is_string($key) && isset(static::$templates[$key]);
    }

    public static function get(string $key): ?OrderConfigTemplate
    {
        return static::has($key) ? app(static::$templates[$key]) : null;
    }

    /**
     * Every template, for people choosing one, with what it sets up: the step labels along its
     * main path and how many steps, policies, triggers and custom fields it brings.
     *
     * @return array<int, array{key: string, name: string, description: string, steps: array<int, string>, summary: array{steps: int, policies: int, triggers: int, custom_fields: int}}>
     */
    public static function describe(): array
    {
        return array_values(array_map(function (string $class) {
            $template   = app($class);
            $attributes = $template->attributes();
            $flow       = is_array($attributes['flow'] ?? null) ? $attributes['flow'] : [];

            return [
                'key'         => $template->key(),
                'name'        => $template->name(),
                'description' => $template->description(),
                'steps'       => static::mainPath($flow, data_get($attributes, 'meta.lifecycle.initial')),
                'summary'     => [
                    'steps'         => count($flow),
                    'policies'      => static::countPolicies($attributes['policies'] ?? []),
                    'triggers'      => count($attributes['triggers'] ?? []) + array_sum(array_map(fn ($activity) => count($activity['actions'] ?? []), $flow)),
                    'custom_fields' => count($template->customFields()),
                ],
            ];
        }, static::$templates));
    }

    /**
     * The status labels from the first step, following each step's first next step.
     */
    protected static function mainPath(array $flow, ?string $initial = null): array
    {
        $code   = $initial ?: (isset($flow['created']) ? 'created' : array_key_first($flow));
        $labels = [];
        $seen   = [];
        while ($code && isset($flow[$code]) && !isset($seen[$code])) {
            $seen[$code] = true;
            $labels[]    = (string) ($flow[$code]['status'] ?? $code);
            $code        = $flow[$code]['activities'][0] ?? null;
        }

        return $labels;
    }

    /**
     * How many registered policies the template sets anything on.
     */
    protected static function countPolicies(array $policies): int
    {
        return count(array_filter(array_keys(OrderConfigPolicies::all()), fn (string $key) => !empty(data_get($policies, $key))));
    }

    /**
     * The attributes of the system config made from the template (`system:order-config:<key>`).
     */
    public static function attributes(string $key): array
    {
        $template = static::get($key);
        if (!$template) {
            throw new \InvalidArgumentException('No order config template named ' . $key . '.');
        }

        return array_replace([
            'name'         => $template->name(),
            'key'          => $template->key(),
            'namespace'    => 'system:order-config:' . $template->key(),
            'core_service' => 1,
            'status'       => 'private',
            'version'      => '0.0.1',
        ], $template->attributes());
    }

    /**
     * The company's config from the template, made when it doesn't have one yet. Without a name
     * it is the company's system config for the template (`system:order-config:<key>`), found
     * again on the next call; with a name it is a new config of that name.
     */
    public static function create(string $key, Company $company, ?string $name = null): OrderConfig
    {
        $template = static::get($key);
        if (!$template) {
            throw new \InvalidArgumentException('No order config template named ' . $key . '.');
        }

        $attributes = static::attributes($key);

        if ($name) {
            $config = OrderConfig::create(array_replace($attributes, [
                'company_uuid' => $company->uuid,
                'name'         => $name,
                'core_service' => 0,
                'key'          => Str::slug($name),
                'namespace'    => Str::slug($company->name) . ':order-config:' . Str::slug($name),
            ]));
        } else {
            $config = OrderConfig::firstOrCreate(
                ['company_uuid' => $company->uuid, 'key' => $attributes['key'], 'namespace' => $attributes['namespace']],
                $attributes
            );
        }

        if ($config->wasRecentlyCreated) {
            foreach ($template->customFields() as $field) {
                $config->customFields()->create(array_replace($field, ['company_uuid' => $company->uuid]));
            }
        }

        return $config;
    }
}
