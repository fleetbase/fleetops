<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics;
use Fleetbase\FleetOps\Support\Analytics\FuelEfficiency;
use Fleetbase\FleetOps\Support\Analytics\FuelProviderSummary;
use Fleetbase\FleetOps\Support\Analytics\GeofenceViolations;
use Fleetbase\FleetOps\Support\Analytics\IssuesInsights;
use Fleetbase\FleetOps\Support\Analytics\LiveFleet;
use Fleetbase\FleetOps\Support\Analytics\MaintenanceOverview;
use Fleetbase\FleetOps\Support\Analytics\OnTimeDelivery;
use Fleetbase\FleetOps\Support\Analytics\OperationsPulse;
use Fleetbase\FleetOps\Support\Analytics\OrdersByStatus;
use Fleetbase\FleetOps\Support\Analytics\RevenueTrend;
use Fleetbase\FleetOps\Support\Analytics\TopDrivers;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * One public method per dashboard widget. Each method:
 *   - Resolves a period from ?period=7d|30d|... or explicit start/end
 *   - Constructs the corresponding Support\Analytics\* helper
 *   - Returns the helper's JSON payload (Chart.js datasets, ranked rows,
 *     scalar tiles, etc. — shape is widget-specific)
 *
 * Cache headers are set per-widget by the route middleware in routes.php.
 */
class AnalyticsController extends Controller
{
    public function operationsPulse(Request $request)
    {
        return $this->run($request, OperationsPulse::class);
    }

    public function revenueTrend(Request $request)
    {
        return $this->run($request, RevenueTrend::class, function (RevenueTrend $a) use ($request) {
            $a->groupBy($request->string('group_by', 'day')->toString());
        });
    }

    public function ordersByStatus(Request $request)
    {
        return $this->run($request, OrdersByStatus::class, defaultDays: 14);
    }

    public function onTimeDelivery(Request $request)
    {
        return $this->run($request, OnTimeDelivery::class, function (OnTimeDelivery $a) use ($request) {
            $a->slaMinutes((int) $request->input('sla_minutes', 30));
        });
    }

    public function topDrivers(Request $request)
    {
        return $this->run($request, TopDrivers::class, function (TopDrivers $a) use ($request) {
            $a->limit((int) $request->input('limit', 10));
            $a->sortBy($request->string('sort_by', 'orders_completed')->toString());
        });
    }

    public function fuelEfficiency(Request $request)
    {
        return $this->run($request, FuelEfficiency::class, defaultDays: 90);
    }

    public function fuelProviders(Request $request)
    {
        return $this->run($request, FuelProviderSummary::class, defaultDays: 30);
    }

    public function issuesInsights(Request $request)
    {
        return $this->run($request, IssuesInsights::class);
    }

    public function maintenanceOverview(Request $request)
    {
        return $this->run($request, MaintenanceOverview::class);
    }

    public function geofenceViolations(Request $request)
    {
        return $this->run($request, GeofenceViolations::class, defaultDays: 7);
    }

    public function liveFleet(Request $request)
    {
        return $this->run($request, LiveFleet::class);
    }

    /**
     * Shared boilerplate: period parsing + helper construction + JSON response.
     *
     * @param class-string<AbstractAnalytics> $class
     */
    private function run(Request $request, string $class, ?callable $configure = null, int $defaultDays = 30)
    {
        [$start, $end] = AbstractAnalytics::resolvePeriod(
            $request->string('period')->toString() ?: null,
            $request->date('start'),
            $request->date('end'),
            $defaultDays,
        );

        try {
            /** @var AbstractAnalytics $analytics */
            $analytics = $class::forCompany($request->user()->company)->between($start, $end);

            if ($configure !== null) {
                $configure($analytics);
            }

            return response()->json($analytics->get());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error'   => $e->getMessage(),
                'widget'  => $class,
            ], 500);
        }
    }
}
