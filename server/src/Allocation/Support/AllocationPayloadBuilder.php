<?php

namespace Fleetbase\FleetOps\Allocation\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * AllocationPayloadBuilder
 *
 * Transforms FleetOps Order and Vehicle/Driver records into a normalized
 * intermediate representation that engine adapters can consume. This class
 * is engine-agnostic — it does not produce VROOM JSON directly. Each engine
 * adapter (e.g. VroomAllocationEngine) calls the builder and then maps the
 * normalized output to its own wire format.
 *
 * The builder now reads first-class orchestrator columns (skills, capacity_*,
 * time_window_*, service_time, orchestrator_priority) added by the 2026-04-08
 * migrations, falling back to custom fields and meta for backwards compatibility.
 */
class AllocationPayloadBuilder
{
    /**
     * Build the normalized job list from a collection of Orders.
     *
     * Each job contains:
     *   - id:            order public_id
     *   - location:      [longitude, latitude] of the delivery destination
     *   - pickup:        [longitude, latitude] of the pickup (for PDPTW shipments)
     *   - service:       service time in seconds (waypoint.service_time → order meta → default 300)
     *   - time_windows:  [[earliest_unix, latest_unix]] from order.time_window_start/end or scheduled_at
     *   - skills:        integer skill codes from order.required_skills or custom fields
     *   - amount:        multi-dimensional capacity demand [weight_kg, volume_m3, pallets, parcels]
     *   - priority:      orchestrator_priority (0–100, higher = more important)
     *   - description:   human-readable label for debugging
     *
     * @param  Collection $orders
     * @return array
     */
    public static function buildJobs(Collection $orders): array
    {
        return $orders->map(function (Order $order) {
            $payload     = $order->payload;
            $destination = $payload?->dropoff ?? $payload?->waypoints?->last();

            if (!$destination || !$destination->location) {
                return null;
            }

            // --- Location ---
            $job = [
                'id'          => $order->public_id,
                'location'    => [$destination->location->getLng(), $destination->location->getLat()],
                'description' => $order->public_id,
            ];

            // Pickup location (for pickup-and-delivery problems)
            $pickup = $payload?->pickup;
            if ($pickup && $pickup->location) {
                $job['pickup'] = [$pickup->location->getLng(), $pickup->location->getLat()];
            }

            // --- Service time ---
            // Prefer waypoint-level service_time, then order meta, then default 300s
            $waypointMarker = $payload?->waypointMarkers?->last();
            $job['service'] = (int) (
                $waypointMarker?->service_time
                ?? $order->getMeta('service_time_seconds')
                ?? 300
            );

            // --- Capacity demand (multi-dimensional) ---
            // [weight_kg, volume_m3, pallets, parcels] — must match vehicle capacity array
            $job['amount'] = [
                (int) round((float) ($payload?->capacity_weight_kg ?? $order->getMeta('weight_kg') ?? 0)),
                (int) round((float) ($payload?->capacity_volume_m3 ?? $order->getMeta('volume_m3') ?? 0) * 1000), // store as litres for integer
                (int) ($payload?->capacity_pallets ?? $order->getMeta('pallets') ?? 0),
                (int) ($payload?->capacity_parcels ?? $order->getMeta('parcels') ?? 1),
            ];

            // --- Time windows ---
            // Prefer explicit orchestrator time_window columns, fall back to scheduled_at
            if ($order->time_window_start && $order->time_window_end) {
                $job['time_windows'] = [[
                    $order->time_window_start->timestamp,
                    $order->time_window_end->timestamp,
                ]];
            } elseif ($order->scheduled_at) {
                $start = $order->scheduled_at->timestamp;
                $end   = $order->scheduled_at->copy()->addHours(4)->timestamp;
                $job['time_windows'] = [[$start, $end]];
            }

            // --- Skills ---
            // Prefer first-class required_skills JSON column, fall back to custom fields
            $skills = static::resolveSkills(
                $order->required_skills ?? [],
                $order->custom_fields ?? []
            );
            if (!empty($skills)) {
                $job['skills'] = $skills;
            }

            // --- Priority ---
            if ($order->orchestrator_priority !== null && $order->orchestrator_priority > 0) {
                $job['priority'] = (int) $order->orchestrator_priority;
            }

            return $job;
        })->filter()->values()->toArray();
    }

    /**
     * Build the normalized vehicle list from a collection of Vehicles.
     *
     * Each vehicle entry contains:
     *   - id:           vehicle public_id
     *   - driver_id:    driver public_id (for result mapping)
     *   - start:        [longitude, latitude] of the driver's current position
     *   - end:          [longitude, latitude] of the depot/return location (if return_to_depot)
     *   - capacity:     [max_weight_kg, max_volume_l, max_pallets, max_parcels]
     *   - max_tasks:    maximum number of stops this vehicle can handle
     *   - max_travel_time: maximum travel time in seconds (from driver or vehicle)
     *   - time_window:  [shift_start_unix, shift_end_unix] from driver shift or time_window columns
     *   - skills:       integer skill codes from vehicle.skills + driver.skills or custom fields
     *
     * @param  Collection $vehicles
     * @return array
     */
    public static function buildVehicles(Collection $vehicles): array
    {
        return $vehicles->map(function (Vehicle $vehicle) {
            $driver = $vehicle->driver;

            if (!$driver || !$driver->location) {
                return null;
            }

            // --- Start position ---
            $entry = [
                'id'        => $vehicle->public_id,
                'driver_id' => $driver->public_id,
                'start'     => [$driver->location->getLng(), $driver->location->getLat()],
            ];

            // --- Return to depot ---
            if ($vehicle->return_to_depot && $driver->location) {
                $entry['end'] = [$driver->location->getLng(), $driver->location->getLat()];
            }

            // --- Multi-dimensional capacity ---
            // [weight_kg, volume_l (×1000 from m3), pallets, parcels]
            $entry['capacity'] = [
                (int) round((float) ($vehicle->capacity_weight_kg ?? $vehicle->getMeta('max_weight_kg') ?? 0)),
                (int) round((float) ($vehicle->capacity_volume_m3 ?? $vehicle->getMeta('max_volume_m3') ?? 0) * 1000),
                (int) ($vehicle->capacity_pallets ?? $vehicle->getMeta('max_pallets') ?? 0),
                (int) ($vehicle->capacity_parcels ?? $vehicle->getMeta('max_parcels') ?? 100),
            ];

            // --- Max tasks ---
            if ($vehicle->max_tasks !== null && $vehicle->max_tasks > 0) {
                $entry['max_tasks'] = (int) $vehicle->max_tasks;
            }

            // --- Max travel time (seconds) ---
            $maxTravel = $driver->max_travel_time ?? $vehicle->getMeta('max_travel_time_seconds');
            if ($maxTravel) {
                $entry['max_travel_time'] = (int) $maxTravel;
            }

            // --- Time window ---
            // Priority: driver.time_window_start/end columns → active shift → vehicle.time_window columns
            if ($driver->time_window_start && $driver->time_window_end) {
                $entry['time_window'] = [
                    $driver->time_window_start->timestamp,
                    $driver->time_window_end->timestamp,
                ];
            } else {
                $activeShift = $driver->activeShiftFor(now());
                if ($activeShift) {
                    $entry['time_window'] = [
                        $activeShift->start_at->timestamp,
                        $activeShift->end_at->timestamp,
                    ];
                } elseif ($vehicle->time_window_start && $vehicle->time_window_end) {
                    $entry['time_window'] = [
                        $vehicle->time_window_start->timestamp,
                        $vehicle->time_window_end->timestamp,
                    ];
                }
            }

            // --- Skills ---
            $skills = array_values(array_unique(array_merge(
                static::resolveSkills($vehicle->skills ?? [], $vehicle->custom_fields ?? []),
                static::resolveSkills($driver->skills ?? [], $driver->custom_fields ?? [])
            )));
            if (!empty($skills)) {
                $entry['skills'] = $skills;
            }

            return $entry;
        })->filter()->values()->toArray();
    }

    /**
     * Resolve integer skill codes from a first-class skills array and/or custom fields.
     *
     * The skills array (from the new JSON column) contains string identifiers like
     * "cold_chain", "hazmat", "fragile". These are hashed to stable positive integers.
     * Custom fields that are boolean-true are also included for backwards compatibility.
     *
     * @param  array $skills       String skill identifiers from the skills JSON column
     * @param  array $customFields Custom fields array from HasCustomFields trait
     * @return array<int>
     */
    public static function resolveSkills(array $skills, array $customFields = []): array
    {
        $codes = [];

        // First-class skills column: hash each string identifier to a stable integer
        foreach ($skills as $skill) {
            if (is_string($skill) && !empty($skill)) {
                $codes[] = abs(crc32($skill)) % 2147483647 + 1;
            }
        }

        // Backwards-compatible custom fields: boolean-true fields treated as skills
        foreach ($customFields as $key => $value) {
            if ($value === true || $value === 'true' || $value === '1' || $value === 1) {
                $codes[] = abs(crc32($key)) % 2147483647 + 1;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @deprecated Use resolveSkills() instead.
     */
    protected static function extractSkillsFromCustomFields(array $customFields): array
    {
        return static::resolveSkills([], $customFields);
    }
}
