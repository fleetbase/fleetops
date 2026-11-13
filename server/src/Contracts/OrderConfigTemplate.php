<?php

namespace Fleetbase\FleetOps\Contracts;

/**
 * A ready-made order config for one kind of operation (transport, passenger trips, and so on):
 * its flow, lifecycle, policies, triggers and custom fields. A company gets a config from a
 * template and can change it like any other.
 */
interface OrderConfigTemplate
{
    /**
     * The key of configs made from the template, and of the template in the registry.
     */
    public function key(): string;

    public function name(): string;

    public function description(): string;

    /**
     * The config's attributes: `description`, `tags`, `flow`, `meta`, `policies`, `triggers`,
     * `entities`.
     */
    public function attributes(): array;

    /**
     * Custom fields orders of the config carry, as core custom field attributes.
     */
    public function customFields(): array;
}
