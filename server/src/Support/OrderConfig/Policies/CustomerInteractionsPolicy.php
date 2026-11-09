<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

/**
 * What a customer can do with the driver of their order through the customer API.
 */
class CustomerInteractionsPolicy extends Policy
{
    protected string $key         = 'customer.interactions';
    protected string $label       = 'Customer and driver';
    protected string $description = 'Let customers message the driver of their order, rate the driver once it is completed, and share a live tracking link.';

    protected array $defaults = [
        'chat'           => false,
        'rating'         => false,
        'share_tracking' => false,
    ];

    protected array $rules = [
        'chat'           => ['nullable', 'boolean'],
        'rating'         => ['nullable', 'boolean'],
        'share_tracking' => ['nullable', 'boolean'],
    ];
}
