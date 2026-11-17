<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

use Fleetbase\FleetOps\Contracts\OrderConfigPolicy;

/**
 * A policy described by its properties, which is all most policies need.
 */
abstract class Policy implements OrderConfigPolicy
{
    protected string $key;
    protected string $label;
    protected string $description;
    protected array $defaults = [];
    protected array $rules    = [];
    protected array $choices  = [];

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function defaults(): array
    {
        return $this->defaults;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function choices(): array
    {
        return $this->choices;
    }
}
