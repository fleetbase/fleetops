<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Templates;

/**
 * The default config every company gets: an order is created, dispatched, started, driven and
 * completed.
 */
class TransportTemplate extends Template
{
    protected string $key         = 'transport';
    protected string $name        = 'Transport';
    protected string $description = 'Default order configuration for transport';

    public function attributes(): array
    {
        return [
            'description' => $this->description,
            'tags'        => ['transport', 'delivery'],
            'entities'    => [],
            'meta'        => [],
            'flow'        => [
                'created'    => static::activity('created', 'Order Created', 'New order was created.', ['dispatched']),
                'enroute'    => static::activity('enroute', 'Driver Enroute', 'Driver is en-route.', ['completed']),
                'started'    => static::activity('started', 'Order Started', 'Order has been started', ['enroute']),
                'completed'  => static::activity('completed', 'Order Completed', 'Order has been completed.', [], true),
                'dispatched' => static::activity('dispatched', 'Order Dispatched', 'Order has been dispatched.', ['started']),
            ],
        ];
    }
}
