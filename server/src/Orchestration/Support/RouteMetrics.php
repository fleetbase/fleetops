<?php

namespace Fleetbase\FleetOps\Orchestration\Support;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Collection;

/**
 * RouteMetrics.
 *
 * Estimates per-stop arrival times and route distance/duration for engines
 * that only decide a sequence (greedy, the built-in route sequencer) or for
 * solver routes that came back without some of those figures.
 *
 * Legs are measured with FleetOps' local distance-matrix calculation
 * (Utils::calculateDrivingDistanceAndTime), which needs no routing provider,
 * so estimates are always available and are flagged as such in the summary.
 *
 * Assignment fields follow the VROOM step convention so every engine
 * produces the same shape:
 *   - arrival:        unix timestamp the vehicle reaches the order's last stop
 *   - distance:       cumulative route distance (m) at that stop
 *   - duration:       cumulative travel time (s) at that stop
 *   - route_distance: total distance (m) of the vehicle's route
 *   - route_duration: total travel time (s) of the vehicle's route
 */
class RouteMetrics
{
    /**
     * Measure an ordered stop list.
     *
     * @param array|null $start     [lat, lng] the vehicle starts from, or null to start at the first stop
     * @param array      $stops     ordered list of ['order_id' => string, 'lat' => float, 'lng' => float]
     * @param int        $startTime unix timestamp the route starts
     *
     * @return array{orders: array<string, array{arrival: int, distance: int, duration: int}>, distance: int, duration: int}
     */
    public static function measure(?array $start, array $stops, int $startTime): array
    {
        $distance = 0.0;
        $duration = 0.0;
        $previous = $start;
        $orders   = [];

        foreach ($stops as $stop) {
            $current = [(float) $stop['lat'], (float) $stop['lng']];

            if ($previous !== null) {
                $leg = Utils::calculateDrivingDistanceAndTime(new Point($previous[0], $previous[1]), new Point($current[0], $current[1]));
                $distance += (float) $leg->distance;
                $duration += (float) $leg->time;
            }

            // The order's figures are those of its last stop in the route.
            $orders[$stop['order_id']] = [
                'arrival'  => $startTime + (int) round($duration),
                'distance' => (int) round($distance),
                'duration' => (int) round($duration),
            ];
            $previous = $current;
        }

        return [
            'orders'   => $orders,
            'distance' => (int) round($distance),
            'duration' => (int) round($duration),
        ];
    }

    /**
     * Fill in arrival, distance and duration figures the engine did not supply.
     *
     * A route without any engine timing is estimated in full; a route with
     * engine timing keeps the engine's figures and only has gaps filled.
     *
     * @param array      $result   standard orchestration result
     * @param Collection $orders   orders that were orchestrated, keyed or not
     * @param Collection $vehicles vehicles that were available to the run
     */
    public static function annotate(array $result, Collection $orders, Collection $vehicles, ?int $startTime = null): array
    {
        $assignments = $result['assignments'] ?? [];
        if (empty($assignments)) {
            return $result;
        }

        $startTime     = $startTime ?? now()->timestamp;
        $ordersById    = $orders->keyBy('public_id');
        $vehiclesById  = $vehicles->keyBy('public_id');
        $routes        = [];
        $estimated     = false;

        foreach ($assignments as $index => $assignment) {
            $routes[$assignment['vehicle_id'] ?? ''][] = $index;
        }

        foreach ($routes as $vehicleId => $indexes) {
            usort($indexes, fn ($a, $b) => ($assignments[$a]['sequence'] ?? 0) <=> ($assignments[$b]['sequence'] ?? 0));

            $hasEngineTiming = collect($indexes)->contains(fn ($i) => ($assignments[$i]['arrival'] ?? null) !== null
                || ($assignments[$i]['route_duration'] ?? null) !== null
                || ($assignments[$i]['route_distance'] ?? null) !== null);

            $stops = [];
            foreach ($indexes as $i) {
                $order = $ordersById->get($assignments[$i]['order_id'] ?? null);
                if (!$order) {
                    continue;
                }
                foreach (OrchestrationPayloadBuilder::buildRouteStops($order) as $stop) {
                    $stops[] = ['order_id' => $order->public_id, 'lng' => $stop['location'][0], 'lat' => $stop['location'][1]];
                }
            }

            $metrics = static::measure(static::vehicleStart($vehiclesById->get($vehicleId), $ordersById, $assignments, $indexes), $stops, $startTime);

            foreach ($indexes as $i) {
                $orderMetrics = $metrics['orders'][$assignments[$i]['order_id'] ?? ''] ?? null;
                $estimate     = [
                    'arrival'        => $orderMetrics['arrival'] ?? null,
                    'distance'       => $orderMetrics['distance'] ?? null,
                    'duration'       => $orderMetrics['duration'] ?? null,
                    'route_distance' => $metrics['distance'],
                    'route_duration' => $metrics['duration'],
                ];

                foreach ($estimate as $key => $value) {
                    if (!$hasEngineTiming || ($assignments[$i][$key] ?? null) === null) {
                        $assignments[$i][$key] = $value;
                        $estimated             = true;
                    }
                }
            }
        }

        $result['assignments'] = $assignments;

        $summary            = $result['summary'] ?? [];
        $routeTotals        = collect($routes)->map(fn ($indexes) => $assignments[$indexes[0]]);
        $summary['distance'] ??= (int) $routeTotals->sum('route_distance');
        $summary['duration'] ??= (int) $routeTotals->sum('route_duration');
        $summary['metrics'] = $estimated ? 'estimated' : ($summary['metrics'] ?? 'engine');
        $result['summary']  = $summary;

        return $result;
    }

    /**
     * Resolve the [lat, lng] a vehicle starts from: the run's vehicle list
     * first, then the vehicle relation of one of its orders.
     */
    protected static function vehicleStart($vehicle, Collection $ordersById, array $assignments, array $indexes): ?array
    {
        if (!$vehicle) {
            $order   = $ordersById->get($assignments[$indexes[0]]['order_id'] ?? null);
            $vehicle = $order?->relationLoaded('vehicle') ? $order->vehicle : null;
        }

        $driver   = $vehicle?->relationLoaded('driver') ? $vehicle->driver : null;
        $location = $driver?->location ?? $vehicle?->location;

        return $location ? [(float) $location->getLat(), (float) $location->getLng()] : null;
    }
}
