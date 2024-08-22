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
    public array $guards = ['sanctum'];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'order',
            'actions' => ['dispatch', 'cancel', 'optimize', 'export', 'import', 'assign-driver-for', 'assign-vehicle-for', 'update-route-for'],
        ],
        [
            'name'    => 'order-config',
            'actions' => ['clone'],
        ],
        [
            'name'    => 'route',
            'actions' => ['optimize'],
        ],
        [
            'name'    => 'service-rate',
            'actions' => ['import'],
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
            'actions' => ['notify', 'assign-vehicle-for', 'assign-order-for', 'dispatch-order-for', 'export', 'import'],
        ],
        [
            'name'    => 'vehicle',
            'actions' => ['assign-driver-for', 'export', 'import'],
        ],
        [
            'name'    => 'fleet',
            'actions' => ['assign-driver-for', 'assign-vehicle-for', 'remove-driver-for', 'remove-vehicle-for', 'export', 'import'],
        ],
        [
            'name'    => 'vendor',
            'actions' => ['subcontract', 'create-order-for', 'export', 'import'],
        ],
        [
            'name'    => 'contact',
            'actions' => ['subcontract', 'create-order-for', 'export', 'import'],
        ],
        [
            'name'    => 'customer',
            'actions' => [],
        ],
        [
            'name'    => 'facilitator',
            'actions' => [],
        ],
        [
            'name'    => 'entity',
            'actions' => [],
        ],
        [
            'name'    => 'activity',
            'actions' => [],
        ],
        [
            'name'    => 'place',
            'actions' => ['export', 'import'],
        ],
        [
            'name'    => 'fuel-report',
            'actions' => ['export', 'import'],
        ],
        [
            'name'    => 'issue',
            'actions' => ['export', 'import'],
        ],
        [
            'name'           => 'navigator-settings',
            'action'         => [],
            'remove_actions' => ['delete', 'export', 'list', 'create'],
        ],
    ];
}
