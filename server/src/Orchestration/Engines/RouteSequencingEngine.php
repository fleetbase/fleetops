<?php

namespace Fleetbase\FleetOps\Orchestration\Engines;

use Illuminate\Support\Collection;

/**
 * RouteSequencingEngine.
 *
 * Used for the `optimize_routes` orchestration mode.
 *
 * Unlike the allocation engines (Greedy, VROOM), this engine does NOT
 * re-assign orders to vehicles. Instead it:
 *
 *   1. Groups the provided orders by their already-assigned vehicle
 *      (vehicle_assigned_uuid).
 *   2. For each vehicle group, sequences the stops in an optimal order
 *      using a nearest-neighbour TSP heuristic.
 *   3. Returns one assignment entry per order, preserving the existing
 *      vehicle_id / driver_id and updating only the sequence number.
 *
 * This ensures that running "Assign Vehicles" followed by "Optimize Routes"
 * produces one properly-sequenced route per vehicle rather than re-running
 * the allocation algorithm.
 */
class RouteSequencingEngine
{
    /**
     * Sequence stops for each vehicle group.
     *
     * @param Collection $orders  Orders with payload.pickup, payload.dropoff, payload.waypoints loaded
     * @param array      $options Engine options (currently unused, reserved for future use)
     *
     * @return array Standard orchestration result: { assignments, unassigned, summary }
     */
    public function sequence(Collection $orders, array $options = []): array
    {
        $assignments = [];
        $unassigned  = [];

        // Group orders by their currently assigned vehicle UUID
        $byVehicle = [];
        foreach ($orders as $order) {
            $vehicleUuid = $order->vehicle_assigned_uuid ?? null;
            if (!$vehicleUuid) {
                // Order has no vehicle assignment — cannot sequence it
                $unassigned[] = $order->public_id;
                continue;
            }
            $byVehicle[$vehicleUuid][] = $order;
        }

        foreach ($byVehicle as $vehicleUuid => $vehicleOrders) {
            $vehicle = $vehicleOrders[0]->vehicle ?? null;

            // Determine driver public_id from the vehicle relationship
            $driverPublicId = null;
            if ($vehicle && $vehicle->driver) {
                $driverPublicId = $vehicle->driver->public_id ?? null;
            }

            // Get vehicle's current location as the starting point for sequencing
            $startLat = null;
            $startLng = null;
            if ($vehicle) {
                $driver   = $vehicle->driver;
                $location = $driver?->location ?? $vehicle->location;
                $startLat = $location?->getLat();
                $startLng = $location?->getLng();
            }

            // Build a flat list of stops: each order contributes pickup + dropoff
            // (or waypoints for multi-drop orders). We keep pickup before its own
            // dropoff as a hard constraint.
            $sequenced = $this->_sequenceOrdersForVehicle($vehicleOrders, $startLat, $startLng);

            // Build assignment entries — one per order, with the sequence number
            // being the position of the order's FIRST stop in the sequenced list.
            $orderSequences = [];
            foreach ($sequenced as $seq => $stop) {
                $orderId = $stop['order_public_id'];
                // Only record the first occurrence (pickup position) per order
                if (!isset($orderSequences[$orderId])) {
                    $orderSequences[$orderId] = $seq + 1; // 1-based
                }
            }

            foreach ($vehicleOrders as $order) {
                $assignments[] = [
                    'order_id'          => $order->public_id,
                    'vehicle_id'        => $vehicle?->public_id ?? $vehicleUuid,
                    'driver_id'         => $driverPublicId,
                    'sequence'          => $orderSequences[$order->public_id] ?? 1,
                    'waypoint_sequence' => null,
                    'arrival'           => null,
                    'duration'          => null,
                    'distance'          => null,
                ];
            }
        }

        return [
            'assignments' => $assignments,
            'unassigned'  => $unassigned,
            'summary'     => [
                'engine'     => 'route_sequencing',
                'assigned'   => count($assignments),
                'unassigned' => count($unassigned),
            ],
        ];
    }

    /**
     * Sequence stops for a single vehicle's orders using nearest-neighbour TSP.
     *
     * Constraints:
     *   - Each order's pickup must appear before its dropoff (precedence constraint).
     *   - Starts from the vehicle/driver's current location if known.
     *
     * @param array      $orders   Array of Order models
     * @param float|null $startLat Vehicle starting latitude
     * @param float|null $startLng Vehicle starting longitude
     *
     * @return array Ordered array of stop records: { order_public_id, lat, lng, type }
     */
    protected function _sequenceOrdersForVehicle(array $orders, ?float $startLat, ?float $startLng): array
    {
        // Build a flat pool of stops with precedence constraints
        $pool = [];
        foreach ($orders as $order) {
            $payload = $order->payload;
            if (!$payload) {
                continue;
            }

            $waypoints    = $payload->waypoints;
            $hasWaypoints = $waypoints && $waypoints->count() > 0;
            $isMultiDrop  = $hasWaypoints && !$payload->pickup_uuid && !$payload->dropoff_uuid;

            if ($isMultiDrop) {
                // Multi-drop: add each waypoint as a stop
                $sorted = $waypoints->sortBy('order')->values();
                foreach ($sorted as $idx => $wp) {
                    $place = $wp->place;
                    if (!$place) {
                        continue;
                    }
                    $pool[] = [
                        'order_public_id'  => $order->public_id,
                        'lat'              => (float) ($place->lat ?? 0),
                        'lng'              => (float) ($place->lng ?? 0),
                        'type'             => 'waypoint',
                        'precedence_after' => $idx > 0 ? ($pool[count($pool) - 1]['id'] ?? null) : null,
                        'id'               => $order->public_id . '_wp_' . $idx,
                    ];
                }
            } else {
                // Standard pickup → dropoff
                $pickup  = $payload->pickup;
                $dropoff = $payload->dropoff;

                $pickupId  = $order->public_id . '_pickup';
                $dropoffId = $order->public_id . '_dropoff';

                if ($pickup && $pickup->lat && $pickup->lng) {
                    $pool[] = [
                        'order_public_id'  => $order->public_id,
                        'lat'              => (float) $pickup->lat,
                        'lng'              => (float) $pickup->lng,
                        'type'             => 'pickup',
                        'precedence_after' => null, // pickup has no prerequisite
                        'id'               => $pickupId,
                        'blocks'           => $dropoffId, // dropoff must come after this
                    ];
                }

                if ($dropoff && $dropoff->lat && $dropoff->lng) {
                    $pool[] = [
                        'order_public_id'  => $order->public_id,
                        'lat'              => (float) $dropoff->lat,
                        'lng'              => (float) $dropoff->lng,
                        'type'             => 'dropoff',
                        'precedence_after' => $pickupId, // must come after pickup
                        'id'               => $dropoffId,
                    ];
                }
            }
        }

        if (empty($pool)) {
            return [];
        }

        // Nearest-neighbour TSP with precedence constraints
        $visited  = [];
        $sequence = [];
        $curLat   = $startLat;
        $curLng   = $startLng;

        while (count($visited) < count($pool)) {
            $bestIdx  = null;
            $bestDist = PHP_INT_MAX;

            foreach ($pool as $idx => $stop) {
                if (in_array($idx, $visited)) {
                    continue;
                }

                // Check precedence: if this stop requires another stop to come first
                if (!empty($stop['precedence_after'])) {
                    $prerequisiteId   = $stop['precedence_after'];
                    $prerequisiteDone = false;
                    foreach ($sequence as $done) {
                        if ($done['id'] === $prerequisiteId) {
                            $prerequisiteDone = true;
                            break;
                        }
                    }
                    if (!$prerequisiteDone) {
                        continue;
                    } // not yet eligible
                }

                if ($curLat === null || $curLng === null) {
                    // No current position — just pick the first eligible stop
                    $bestIdx = $idx;
                    break;
                }

                $dist = $this->_haversine($curLat, $curLng, $stop['lat'], $stop['lng']);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestIdx  = $idx;
                }
            }

            if ($bestIdx === null) {
                // No eligible stop found — add all remaining stops in original order
                // (safety fallback to avoid infinite loop)
                foreach ($pool as $idx => $stop) {
                    if (!in_array($idx, $visited)) {
                        $visited[]  = $idx;
                        $sequence[] = $stop;
                        $curLat     = $stop['lat'];
                        $curLng     = $stop['lng'];
                    }
                }
                break;
            }

            $visited[]  = $bestIdx;
            $sequence[] = $pool[$bestIdx];
            $curLat     = $pool[$bestIdx]['lat'];
            $curLng     = $pool[$bestIdx]['lng'];
        }

        return $sequence;
    }

    /**
     * Haversine distance in metres.
     */
    protected function _haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
