<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

/**
 * What a customer must give, and must have settled, to place an order of this config through
 * the customer API.
 */
class CustomerOrderingPolicy extends Policy
{
    protected string $key         = 'customer.ordering';
    protected string $label       = 'Customer ordering';
    protected string $description = 'The payment methods customers can choose, whether an order needs a quote, and whether earlier orders must be settled first.';

    protected array $defaults = [
        // Payment methods a customer may choose, recorded on the order's payload. Empty: the
        // customer doesn't choose one.
        'payment_methods'    => [],
        // The order must be placed with a service quote.
        'require_quote'      => false,
        // While an earlier order of this config paid by one of these methods is completed but
        // not settled, the customer can't place another.
        'require_settlement' => [],
    ];

    protected array $rules = [
        'payment_methods'      => ['nullable', 'array'],
        'payment_methods.*'    => ['string', 'max:32'],
        'require_quote'        => ['nullable', 'boolean'],
        'require_settlement'   => ['nullable', 'array'],
        'require_settlement.*' => ['string', 'max:32'],
    ];
}
