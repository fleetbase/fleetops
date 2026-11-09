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
     * Every template, for people choosing one.
     *
     * @return array<int, array{key: string, name: string, description: string}>
     */
    public static function describe(): array
    {
        return array_values(array_map(function (string $class) {
            $template = app($class);

            return ['key' => $template->key(), 'name' => $template->name(), 'description' => $template->description()];
        }, static::$templates));
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
