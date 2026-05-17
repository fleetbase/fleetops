<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class LiveOrderQuery
{
    public static array $baseExcludedStatuses   = ['canceled', 'completed', 'expired'];
    public static array $activeExcludedStatuses = ['created', 'completed', 'expired', 'order_canceled', 'canceled', 'pending'];

    public static function make(?string $companyUuid = null, array $options = []): Builder
    {
        $companyUuid       = $companyUuid ?: session('company');
        $exclude           = $options['exclude'] ?? [];
        $active            = $options['active'] ?? false;
        $unassigned        = $options['unassigned'] ?? false;
        $withRelations     = $options['with_relations'] ?? false;
        $applyPermissions  = $options['apply_permissions'] ?? true;

        $query = Order::where('company_uuid', $companyUuid)
            ->whereHas('payload', function ($query) {
                $query->where(function ($q) {
                    $q->whereHas('waypoints');
                    $q->orWhereHas('pickup');
                    $q->orWhereHas('dropoff');
                });
            })
            ->whereNotIn('status', static::$baseExcludedStatuses)
            ->whereHas('trackingNumber')
            ->whereHas('trackingStatuses')
            ->whereNull('deleted_at');

        if (!empty($exclude)) {
            $query->whereNotIn('public_id', $exclude);
        }

        if ($applyPermissions) {
            $query->applyDirectivesForPermissions('fleet-ops list order');
        }

        if ($active) {
            $query->whereHas('driverAssigned');
            $query->whereNotIn('status', static::$activeExcludedStatuses);
        }

        if ($unassigned) {
            $query->whereNull('driver_assigned_uuid');
        }

        if ($withRelations) {
            $query->with([
                'payload.entities',
                'payload.dropoff',
                'payload.pickup',
                'payload.return',
                'payload.firstWaypointMarker.place',
                'payload.lastWaypointMarker.place',
                'trackingNumber',
                'trackingStatuses',
                'driverAssigned' => function ($query) {
                    $query->without(['jobs', 'currentJob']);
                },
                'vehicleAssigned' => function ($query) {
                    $query->without(['fleets', 'vendor']);
                },
                'customer',
                'facilitator',
            ]);
        }

        return $query;
    }
}
