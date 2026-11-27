<?php

namespace Fleetbase\FleetOps\Support\Dispatch;

use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Collection;

/**
 * Where a late order fits best on routes that are already planned.
 *
 * For each route, every gap after the driver's last finished stop is tried:
 * the cost of a gap is the detour `prev → order + order → next − prev → next`.
 * The cheapest gap is the route's best fit. Legs are straight-line estimates
 * from Utils::calculateDrivingDistanceAndTime (no routing provider needed, the
 * same estimate the built-in sequencer uses), so the figures rank routes
 * against each other rather than promise an ETA.
 *
 * The route starts from the driver's last known position when there is one,
 * else from its last finished stop. Stops without coordinates are skipped.
 */
class InsertionEstimator
{
    /**
     * The cheapest insertion on each route, cheapest route first, one entry
     * per driver.
     *
     * @param Collection<int, Manifest> $manifests open routes, with `stops.place` and `driver` loaded
     *
     * @return array<int, array{manifest_id: string, driver_id: ?string, driver_name: ?string, vehicle_id: ?string, status: string, position: int, extra_distance_m: int, extra_duration_s: int, extra_km: float, extra_minutes: float, stop_count: int}>
     */
    public function bestFit(Order $order, Collection $manifests): array
    {
        $target = $this->orderPoint($order);
        if (!$target) {
            return [];
        }

        $candidates = [];
        foreach ($manifests as $manifest) {
            $fit = $this->bestPosition($manifest, $target);

            $candidates[] = [
                'manifest_id'      => $manifest->public_id,
                'driver_id'        => $manifest->driver?->public_id,
                'driver_name'      => $manifest->driver?->name,
                'vehicle_id'       => $manifest->vehicle?->public_id,
                'status'           => $manifest->status,
                'position'         => $fit['position'],
                'extra_distance_m' => (int) round($fit['distance']),
                'extra_duration_s' => (int) round($fit['duration']),
                'extra_km'         => round($fit['distance'] / 1000, 2),
                'extra_minutes'    => round($fit['duration'] / 60, 1),
                'stop_count'       => $manifest->stops->count(),
            ];
        }

        usort($candidates, fn (array $a, array $b) => [$a['extra_distance_m'], $a['extra_duration_s']] <=> [$b['extra_distance_m'], $b['extra_duration_s']]);

        // A driver with two open routes that day is offered once, on the cheaper.
        $seen = [];

        return array_values(array_filter($candidates, function (array $candidate) use (&$seen) {
            $key = $candidate['driver_id'] ?? $candidate['manifest_id'];
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    /**
     * The cheapest gap on one route. Position is 1-based on the whole route,
     * so it can be sent straight to the insert endpoint.
     *
     * @return array{position: int, distance: float, duration: float}
     */
    public function bestPosition(Manifest $manifest, Point $target): array
    {
        $stops    = $manifest->stops->sortBy('sequence')->values();
        $doneUpTo = 0;
        foreach ($stops as $index => $stop) {
            if ($stop->isDone()) {
                $doneUpTo = $index + 1;
            }
        }

        $start = $this->point($manifest->driver?->location) ?? ($doneUpTo > 0 ? $this->stopPoint($stops[$doneUpTo - 1]) : null);

        // Each remaining stop keeps its 1-based route position, so a gap
        // between two located stops maps back to a route position even when
        // stops without coordinates sit in between.
        $remaining = [];
        foreach ($stops->slice($doneUpTo) as $index => $stop) {
            $point = $this->stopPoint($stop);
            if ($point) {
                $remaining[] = ['position' => $index + 1, 'point' => $point];
            }
        }

        $best = null;
        for ($gap = 0; $gap <= count($remaining); $gap++) {
            $prev = $gap === 0 ? $start : $remaining[$gap - 1]['point'];
            $next = $remaining[$gap]['point'] ?? null;

            [$distance, $duration] = $this->detour($prev, $target, $next);

            if ($best === null || $distance < $best['distance']) {
                $best = [
                    'position' => $next ? $remaining[$gap]['position'] : $stops->count() + 1,
                    'distance' => $distance,
                    'duration' => $duration,
                ];
            }
        }

        return $best;
    }

    /**
     * Extra distance and time of visiting `$via` between `$from` and `$to`;
     * either end may be missing (start of a route with no known start, or the
     * end of a route).
     *
     * @return array{0: float, 1: float}
     */
    protected function detour(?Point $from, Point $via, ?Point $to): array
    {
        $distance = 0.0;
        $duration = 0.0;

        if ($from) {
            [$d, $t] = $this->leg($from, $via);
            $distance += $d;
            $duration += $t;
        }
        if ($to) {
            [$d, $t] = $this->leg($via, $to);
            $distance += $d;
            $duration += $t;
        }
        if ($from && $to) {
            [$d, $t] = $this->leg($from, $to);
            $distance -= $d;
            $duration -= $t;
        }

        return [max(0.0, $distance), max(0.0, $duration)];
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function leg(Point $from, Point $to): array
    {
        $matrix = Utils::calculateDrivingDistanceAndTime($from, $to);

        return [(float) $matrix->distance, (float) $matrix->time];
    }

    /**
     * Where the order would be visited: its drop-off, or its last waypoint.
     */
    public function orderPoint(Order $order): ?Point
    {
        $order->loadMissing(['payload.dropoff', 'payload.waypoints']);
        $place = $order->payload?->dropoff ?? $order->payload?->waypoints?->last();

        return $this->point($place?->location);
    }

    protected function stopPoint(ManifestStop $stop): ?Point
    {
        return $this->point($stop->place?->location);
    }

    /**
     * A usable point, or null. POINT(0 0) is the placeholder a driver or
     * place carries before it has a real position.
     */
    protected function point(mixed $location): ?Point
    {
        if (!$location instanceof Point) {
            return null;
        }

        if ((float) $location->getLat() === 0.0 && (float) $location->getLng() === 0.0) {
            return null;
        }

        return $location;
    }
}
