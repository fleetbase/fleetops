<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

/**
 * Whether, and until when, a customer may cancel their own order of this config.
 */
class CustomerCancellationPolicy extends Policy
{
    protected string $key         = 'customer.cancellation';
    protected string $label       = 'Customer cancellation';
    protected string $description = 'Let customers cancel their own orders, up to a chosen activity.';

    protected array $defaults = [
        'enabled'       => true,
        // An activity code: once the order has reached it, the customer can no longer cancel.
        // Empty: any time before the order finishes.
        'allowed_until' => null,
    ];

    protected array $choices = ['allowed_until' => 'activity'];

    protected array $rules = [
        'enabled'       => ['nullable', 'boolean'],
        'allowed_until' => ['nullable', 'string', 'max:64'],
    ];
}
