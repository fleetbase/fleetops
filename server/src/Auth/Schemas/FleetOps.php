<?php

namespace Fleetbase\FleetOps\Auth\Schemas;

class FleetOps
{
    /**
     * The permission schema Name.
     */
    public string $name = 'fleet-ops';

    /**
     * The permission schema Polict Name.
     */
    public string $policyName = 'Fleet-Ops';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['web', 'api'];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'order',
            'actions' => ['dispatch', 'cancel', 'optimize', 'export', 'batch-delete', 'batch-cancel'],
        ],
        [
            'name'    => 'route',
            'actions' => ['optimize'],
        ],
        [
            'name'    => 'service-rate',
            'actions' => [],
        ],
        [
            'name'    => 'zone',
            'actions' => [],
        ],
        [
            'name'    => 'service-area',
            'actions' => [],
        ],
        [
            'name'    => 'driver',
            'actions' => ['notify', 'assign-vehicle-for', 'assign-order-for', 'dispatch-order-for', 'export'],
        ],
        [
            'name'    => 'vehicle',
            'actions' => ['assign-driver-for', 'export'],
        ],
        [
            'name'    => 'vendor',
            'actions' => ['subcontract', 'create-order-for', 'export'],
        ],
        [
            'name'    => 'contact',
            'actions' => ['subcontract', 'create-order-for', 'export'],
        ],
        [
            'name'    => 'place',
            'actions' => ['export'],
        ],
        [
            'name'    => 'fuel-report',
            'actions' => ['export'],
        ],
        [
            'name'    => 'issue',
            'actions' => ['export'],
        ],
    ];
}
