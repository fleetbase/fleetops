<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

/**
 * Which drivers the config's orders may go to.
 */
class DriverMatchingPolicy extends Policy
{
    protected string $key         = 'dispatch.matching';
    protected string $label       = 'Driver matching';
    protected string $description = 'Limit the drivers an order is offered to by vehicle type, and leave out drivers already on an order.';

    protected array $defaults = [
        // The order needs a vehicle of the type of the service it was quoted for.
        'vehicle_type'         => false,
        // Drivers whose current order hasn't finished aren't offered another.
        'exclude_busy_drivers' => false,
    ];

    protected array $rules = [
        'vehicle_type'         => ['nullable', 'boolean'],
        'exclude_busy_drivers' => ['nullable', 'boolean'],
    ];
}
