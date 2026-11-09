<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

/**
 * How the price of the config's orders is settled.
 */
class PricingPolicy extends Policy
{
    protected string $key         = 'pricing';
    protected string $label       = 'Pricing';
    protected string $description = 'Price a completed order again from the distance and time it actually took, through the service rate it was quoted on.';

    protected array $defaults = [
        'reprice_on_completion' => false,
        // The activity from which distance and time are measured. Empty: from when the order started.
        'metering_starts_at'    => null,
    ];

    protected array $choices = ['metering_starts_at' => 'activity'];

    protected array $rules = [
        'reprice_on_completion' => ['nullable', 'boolean'],
        'metering_starts_at'    => ['nullable', 'string', 'max:64'],
    ];
}
