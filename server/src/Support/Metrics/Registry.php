<?php

namespace Fleetbase\FleetOps\Support\Metrics;

/**
 * Explicit slug → metric-class map. Replaces the legacy
 * `array_slice(get_class_methods, 9)` reflection which broke whenever a
 * private helper was added to the wrong position in Metrics.php.
 */
class Registry
{
    /** @var array<string, class-string<AbstractMetric>> */
    public const METRICS = [
        'earnings'                => EarningsMetric::class,
        'fuel_costs'              => FuelCostsMetric::class,
        'total_distance_traveled' => TotalDistanceTraveledMetric::class,
        'total_time_traveled'     => TotalTimeTraveledMetric::class,
        'orders_completed'        => OrdersCompletedMetric::class,
        'orders_canceled'         => OrdersCanceledMetric::class,
        'orders_in_progress'      => OrdersInProgressMetric::class,
        'orders_scheduled'        => OrdersScheduledMetric::class,
        'active_live_orders'      => ActiveLiveOrdersMetric::class,
        'drivers_online'          => DriversOnlineMetric::class,
        'total_drivers'           => TotalDriversMetric::class,
        'total_customers'         => TotalCustomersMetric::class,
        'open_issues'             => OpenIssuesMetric::class,
        'resolved_issues'         => ResolvedIssuesMetric::class,
        'avg_order_value'         => AvgOrderValueMetric::class,
    ];

    public static function resolve(string $slug): ?string
    {
        return self::METRICS[$slug] ?? null;
    }

    /** @return array<string, class-string<AbstractMetric>> */
    public static function all(): array
    {
        return self::METRICS;
    }

    public static function slugs(): array
    {
        return array_keys(self::METRICS);
    }
}
