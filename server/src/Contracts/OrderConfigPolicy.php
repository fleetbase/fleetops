<?php

namespace Fleetbase\FleetOps\Contracts;

/**
 * A group of settings an order config uses to decide how its orders behave, such as how they
 * are offered to drivers or what a customer may do with them. Each policy is stored on the
 * config under its key (`dispatch.adhoc` is `policies.dispatch.adhoc`), and every setting it
 * leaves out takes the policy's default, so a config without policies behaves as before.
 */
interface OrderConfigPolicy
{
    /**
     * Where the policy is stored in the config's `policies`, in dot notation.
     */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /**
     * Every setting with its default.
     */
    public function defaults(): array;

    /**
     * Validation rules for the settings, keyed by setting name.
     */
    public function rules(): array;

    /**
     * The values a setting can take, keyed by setting name: a list of `{value, label}`, or
     * `activity` for one of the config's activity codes. Settings left out take any value their
     * rules allow.
     */
    public function choices(): array;
}
