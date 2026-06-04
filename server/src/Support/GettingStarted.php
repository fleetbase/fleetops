<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\Models\Company;

class GettingStarted
{
    protected Company $company;

    public static function forCompany(Company $company): static
    {
        return new static($company);
    }

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function get(): array
    {
        $steps       = $this->steps();
        $completed   = collect($steps)->filter(fn ($step) => $step['completed'])->count();
        $total       = count($steps);
        $nextStep    = collect($steps)->first(fn ($step) => !$step['completed']);
        $isCompleted = $completed === $total;

        return [
            'profile_source'  => 'generic',
            'profile'         => null,
            'is_completed'    => $isCompleted,
            'progress'        => [
                'completed' => $completed,
                'total'     => $total,
                'percent'   => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            ],
            'next_step'       => $nextStep['key'] ?? null,
            'steps'           => $steps,
            'recommendations' => $this->recommendations(),
        ];
    }

    protected function steps(): array
    {
        $companyUuid = $this->company->uuid;

        $hasDriver = Driver::where('company_uuid', $companyUuid)->exists();
        $hasOrder  = Order::where('company_uuid', $companyUuid)->exists();

        $hasAssignedOrder = Order::where('company_uuid', $companyUuid)
            ->whereNotNull('driver_assigned_uuid')
            ->exists();

        $hasOrderActivity = TrackingStatus::where('tracking_statuses.company_uuid', $companyUuid)
            ->whereNotNull('tracking_statuses.tracking_number_uuid')
            ->whereNotIn('tracking_statuses.code', ['CREATED', 'ORDER_CREATED'])
            ->exists();

        return [
            [
                'key'         => 'add_driver',
                'title'       => 'Add a driver',
                'description' => 'Create your first driver profile so work can be assigned.',
                'estimate'    => '2 min',
                'completed'   => $hasDriver,
                'icon'        => 'id-card',
                'route'       => 'console.fleet-ops.management.drivers.index.new',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/drivers',
            ],
            [
                'key'         => 'create_order',
                'title'       => 'Create an order',
                'description' => 'Add a delivery or service order to start dispatching.',
                'estimate'    => '3 min',
                'completed'   => $hasOrder,
                'icon'        => 'box',
                'route'       => 'console.fleet-ops.operations.orders.index.new',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/orders',
            ],
            [
                'key'         => 'assign_driver',
                'title'       => 'Assign driver to order',
                'description' => 'Connect the order to a driver for execution.',
                'estimate'    => '1 min',
                'completed'   => $hasAssignedOrder,
                'icon'        => 'user-check',
                'route'       => 'console.fleet-ops.operations.orders.index',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/dispatch',
            ],
            [
                'key'         => 'update_activity',
                'title'       => 'Update order activity',
                'description' => 'Move the order through its workflow and capture progress.',
                'estimate'    => '2 min',
                'completed'   => $hasOrderActivity,
                'icon'        => 'route',
                'route'       => 'console.fleet-ops.operations.orders.index',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/tracking',
            ],
        ];
    }

    protected function recommendations(): array
    {
        return [
            [
                'key'         => 'route_optimization',
                'title'       => 'Route Optimization',
                'description' => 'Plan efficient multi-stop routes and reduce time on the road.',
                'icon'        => 'route',
                'accent'      => 'blue',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/orchestrator',
            ],
            [
                'key'         => 'live_fleet',
                'title'       => 'Live Fleet Map',
                'description' => 'Watch active drivers, vehicles, and orders in real time.',
                'icon'        => 'map-location-dot',
                'accent'      => 'green',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/live-map',
            ],
            [
                'key'         => 'service_rates',
                'title'       => 'Service Rates',
                'description' => 'Create pricing rules for deliveries, zones, and service areas.',
                'icon'        => 'receipt',
                'accent'      => 'amber',
                'docs_url'    => 'https://www.fleetbase.io/docs/fleet-ops/service-rates',
            ],
            [
                'key'         => 'customer_portal',
                'title'       => 'Customer Portal',
                'description' => 'Let customers submit orders and track deliveries from a branded portal.',
                'icon'        => 'store',
                'accent'      => 'purple',
                'docs_url'    => 'https://www.fleetbase.io/docs/customer-portal',
            ],
        ];
    }
}
