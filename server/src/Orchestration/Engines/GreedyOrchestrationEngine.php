<?php

namespace Fleetbase\FleetOps\Orchestration\Engines;

use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Illuminate\Support\Collection;

/**
 * GreedyOrchestrationEngine.
 *
 * A simple, dependency-free allocation engine that assigns orders to vehicles
 * without requiring an external routing service such as VROOM.
 *
 * Algorithm:
 *   1. Sort orders by priority (descending) then scheduled_at (ascending).
 *   2. For each order, find the nearest available vehicle (by Haversine distance
 *      from the vehicle/driver location to the order pickup point).
 *   3. Assign the order to that vehicle and mark the vehicle as "occupied" for
 *      the purpose of this run (one order per vehicle per run by default; pass
 *      `allow_multi_order = true` in $options to allow multiple orders per vehicle).
 *
 * This engine is registered as 'greedy' in the OrchestrationEngineRegistry and is
 * used automatically when VROOM is unavailable or when the user explicitly
 * selects it in the orchestrator settings.
 */
class GreedyOrchestrationEngine implements OrchestrationEngineInterface
{
    public function getName(): string
    {
        return 'Greedy (built-in)';
    }

    public function getIdentifier(): string
    {
        return 'greedy';
    }

    /**
     * Run the greedy nearest-vehicle assignment.
     */
    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $allowMulti = (bool) ($options['allow_multi_order'] ?? false);

        // Sort orders: highest priority first, then earliest scheduled_at
        $sorted = $orders->sortBy([
            fn ($a, $b) => ($b->orchestrator_priority ?? 0) <=> ($a->orchestrator_priority ?? 0),
            fn ($a, $b) => ($a->scheduled_at ?? now()) <=> ($b->scheduled_at ?? now()),
        ])->values();

        // Build a mutable list of available vehicles with their current location
        $vehiclePool = $vehicles->map(function ($vehicle) {
            $driver   = $vehicle->driver;
            $location = $driver?->location ?? $vehicle->location;

            return [
                'model'     => $vehicle,
                'id'        => $vehicle->public_id,
                'driver_id' => $driver?->public_id,
                'lat'       => $location?->getLat(),
                'lng'       => $location?->getLng(),
                'assigned'  => 0,
            ];
        })->values()->toArray();

        $assignments = [];
        $unassigned  = [];

        foreach ($sorted as $order) {
            $pickupLat = $order->payload?->pickup?->lat ?? null;
            $pickupLng = $order->payload?->pickup?->lng ?? null;

            // Find the best vehicle: nearest with capacity remaining
            $bestIdx  = null;
            $bestDist = PHP_INT_MAX;

            foreach ($vehiclePool as $idx => $v) {
                // In single-order mode, skip vehicles that already have an assignment
                if (!$allowMulti && $v['assigned'] > 0) {
                    continue;
                }

                if ($v['lat'] === null || $v['lng'] === null) {
                    // Vehicle has no known location — eligible but deprioritised
                    if ($bestIdx === null) {
                        $bestIdx = $idx;
                    }
                    continue;
                }

                if ($pickupLat !== null && $pickupLng !== null) {
                    $dist = $this->haversineDistance($v['lat'], $v['lng'], (float) $pickupLat, (float) $pickupLng);
                } else {
                    $dist = 0; // No pickup location — treat as zero distance
                }

                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestIdx  = $idx;
                }
            }

            if ($bestIdx === null) {
                $unassigned[] = $order->public_id;
                continue;
            }

            $v = $vehiclePool[$bestIdx];

            $assignments[] = [
                'order_id'   => $order->public_id,
                'vehicle_id' => $v['id'],
                'driver_id'  => $v['driver_id'],
                'sequence'   => ++$vehiclePool[$bestIdx]['assigned'],
                'arrival'    => null,
                'duration'   => null,
                'distance'   => $bestDist < PHP_INT_MAX ? (int) round($bestDist) : null,
            ];

            // Update vehicle's "current position" to the order dropoff so the
            // next order assigned to this vehicle is measured from the dropoff.
            if ($allowMulti) {
                $dropoffLat = $order->payload?->dropoff?->lat;
                $dropoffLng = $order->payload?->dropoff?->lng;
                if ($dropoffLat !== null && $dropoffLng !== null) {
                    $vehiclePool[$bestIdx]['lat'] = (float) $dropoffLat;
                    $vehiclePool[$bestIdx]['lng'] = (float) $dropoffLng;
                }
            }
        }

        return [
            'assignments' => $assignments,
            'unassigned'  => $unassigned,
            'summary'     => [
                'engine'     => 'greedy',
                'assigned'   => count($assignments),
                'unassigned' => count($unassigned),
            ],
        ];
    }

    /**
     * Haversine distance in metres between two lat/lng points.
     */
    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
