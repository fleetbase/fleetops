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
 * The builder reads custom fields from both orders and vehicles using the
 * HasCustomFields trait so that operators can map domain-specific attributes
 * (e.g. "requires_refrigeration", "max_weight_kg") to engine constraints
 * without modifying core schema.
 */
class AllocationPayloadBuilder
{
    /**
     * Build the normalized job list from a collection of Orders.
     *
     * Each job contains:
     *   - id:           order public_id
     *   - location:     [longitude, latitude] of the delivery destination
     *   - service:      estimated service time in seconds (default 300)
     *   - time_windows: [[earliest_start_unix, latest_end_unix]] if scheduled_at is set
     *   - skills:       integer skill codes derived from order custom fields
     *   - amount:       [1] — unit demand for capacity constraint
     *   - description:  human-readable label for debugging
     *
     * @param  Collection $orders
     * @return array
     */
    public static function buildJobs(Collection $orders): array
    {
        return $orders->map(function (Order $order) {
            $destination = $order->payload?->dropoff ?? $order->payload?->waypoints?->last();

            if (!$destination || !$destination->location) {
                return null;
            }

            $job = [
                'id'          => $order->public_id,
                'location'    => [$destination->location->getLng(), $destination->location->getLat()],
                'service'     => (int) ($order->getMeta('service_time_seconds') ?? 300),
                'amount'      => [1],
                'description' => $order->public_id,
            ];

            // Inject time window from scheduled_at if present
            if ($order->scheduled_at) {
                $start = $order->scheduled_at->timestamp;
                // Default 4-hour delivery window
                $end   = $order->scheduled_at->addHours(4)->timestamp;
                $job['time_windows'] = [[$start, $end]];
            }

            // Map order custom fields to integer skill codes
            $skills = static::extractSkillsFromCustomFields($order->custom_fields ?? []);
            if (!empty($skills)) {
                $job['skills'] = $skills;
            }

            return $job;
        })->filter()->values()->toArray();
    }

    /**
     * Build the normalized vehicle list from a collection of Vehicles.
     *
     * Each vehicle entry contains:
     *   - id:          vehicle public_id
     *   - driver_id:   driver public_id (for result mapping)
     *   - start:       [longitude, latitude] of the driver's current position
     *   - capacity:    [max_capacity] from vehicle meta or default 100
     *   - time_window: [shift_start_unix, shift_end_unix] if driver has active shift
     *   - skills:      integer skill codes derived from vehicle/driver custom fields
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

            $entry = [
                'id'       => $vehicle->public_id,
                'driver_id' => $driver->public_id,
                'start'    => [$driver->location->getLng(), $driver->location->getLat()],
                'capacity' => [(int) ($vehicle->getMeta('max_capacity') ?? 100)],
            ];

            // Inject shift time_window if the driver has an active shift today.
            // This is a hard constraint — the solver will not assign a route that
            // would cause the driver to finish after their shift end time.
            $activeShift = $driver->activeShiftFor(now());
            if ($activeShift) {
                $entry['time_window'] = [
                    $activeShift->start_at->timestamp,
                    $activeShift->end_at->timestamp,
                ];
            }

            // Map vehicle/driver custom fields to integer skill codes
            $skills = array_merge(
                static::extractSkillsFromCustomFields($vehicle->custom_fields ?? []),
                static::extractSkillsFromCustomFields($driver->custom_fields ?? [])
            );
            if (!empty($skills)) {
                $entry['skills'] = array_values(array_unique($skills));
            }

            return $entry;
        })->filter()->values()->toArray();
    }

    /**
     * Extract integer skill codes from a custom fields array.
     *
     * Custom fields that represent boolean capabilities (e.g. "requires_cold_chain")
     * are hashed to a stable integer so that both orders and vehicles can declare
     * the same skill without a shared enum. The hash is deterministic within a
     * single allocation run.
     *
     * @param  array $customFields
     * @return array<int>
     */
    protected static function extractSkillsFromCustomFields(array $customFields): array
    {
        $skills = [];

        foreach ($customFields as $key => $value) {
            // Only boolean true fields are treated as skill requirements
            if ($value === true || $value === 'true' || $value === '1' || $value === 1) {
                // Map to a positive integer in the range [1, 2^31-1]
                $skills[] = abs(crc32($key)) % 2147483647 + 1;
            }
        }

        return $skills;
    }
}
