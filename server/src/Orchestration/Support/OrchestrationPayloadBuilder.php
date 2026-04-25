<?php

namespace Fleetbase\FleetOps\Orchestration\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * OrchestrationPayloadBuilder.
 *
 * Transforms FleetOps Order and Vehicle/Driver records into a normalized
 * intermediate representation that engine adapters can consume. This class
 * is engine-agnostic — it does not produce VROOM JSON directly. Each engine
 * adapter (e.g. VroomOrchestrationEngine) calls the builder and then maps the
 * normalized output to its own wire format.
 *
 * Vehicle capacity is read from the existing first-class columns on the
 * vehicles table (payload_capacity, payload_capacity_volume, etc.).
 *
 * Order/payload capacity demand is computed dynamically by aggregating the
 * weight and dimensions of the payload's entities — no denormalised cache
 * columns are needed or used on the payloads table.
 */
class OrchestrationPayloadBuilder
{
    /**
     * Safely read a meta key from a model, returning $default on any error.
     *
     * Vehicle::getAllMeta() has a strict array return type but some rows store
     * the meta column as a raw JSON string rather than a decoded array, causing
     * a TypeError at runtime. This wrapper catches that and any other meta
     * read failure so a single bad vehicle row does not abort the whole run.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected static function safeMeta($model, string $key, $default = null)
    {
        try {
            return $model->getMeta($key) ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Compute the multi-dimensional capacity demand for a payload by aggregating
     * its entities' weight and volume values.
     *
     * Returns a 4-element integer array matching the vehicle capacity array:
     *   [weight_kg, volume_litres, pallets, parcels]
     *
     * Weight is normalised to kg from entity.weight_unit.
     * Volume is derived from entity length × width × height (dimensions_unit normalised to metres)
     * and expressed in litres (×1000) so it can be stored as an integer for VROOM.
     *
     * Falls back to order meta keys (weight_kg, volume_m3, pallets, parcels) for
     * backwards compatibility with orders that were created before entity-level
     * dimension data was captured.
     */
    protected static function computePayloadDemand(Order $order): array
    {
        $payload = $order->payload;

        if ($payload && $payload->entities && $payload->entities->isNotEmpty()) {
            $totalWeightKg  = 0.0;
            $totalVolumeLit = 0.0;
            $totalParcels   = 0;

            foreach ($payload->entities as $entity) {
                // --- Weight ---
                $rawWeight  = (float) ($entity->weight ?? 0);
                $weightUnit = strtolower($entity->weight_unit ?? 'kg');
                $weightKg   = match ($weightUnit) {
                    'g', 'gram', 'grams'              => $rawWeight / 1000,
                    'lb', 'lbs', 'pound', 'pounds'    => $rawWeight * 0.453592,
                    'oz', 'ounce', 'ounces'            => $rawWeight * 0.0283495,
                    't', 'ton', 'tonne', 'tonnes'      => $rawWeight * 1000,
                    default                            => $rawWeight, // kg assumed
                };
                $totalWeightKg += $weightKg;

                // --- Volume (L × W × H → m³ → litres) ---
                $l    = (float) ($entity->length ?? 0);
                $w    = (float) ($entity->width ?? 0);
                $h    = (float) ($entity->height ?? 0);
                $unit = strtolower($entity->dimensions_unit ?? 'm');

                if ($l > 0 && $w > 0 && $h > 0) {
                    // Normalise to metres
                    $factor = match ($unit) {
                        'cm', 'centimeter', 'centimetre'   => 0.01,
                        'mm', 'millimeter', 'millimetre'   => 0.001,
                        'in', 'inch', 'inches'             => 0.0254,
                        'ft', 'foot', 'feet'               => 0.3048,
                        default                            => 1.0, // metres assumed
                    };
                    $volumeM3    = ($l * $factor) * ($w * $factor) * ($h * $factor);
                    $totalVolumeLit += $volumeM3 * 1000; // m³ → litres
                }

                $totalParcels++;
            }

            return [
                (int) round($totalWeightKg),
                (int) round($totalVolumeLit),
                0, // pallet count not tracked at entity level
                $totalParcels,
            ];
        }

        // Fallback: order meta keys for backwards compatibility
        return [
            (int) round((float) ($order->getMeta('weight_kg') ?? 0)),
            (int) round((float) ($order->getMeta('volume_m3') ?? 0) * 1000),
            (int) ($order->getMeta('pallets') ?? 0),
            (int) ($order->getMeta('parcels') ?? 1),
        ];
    }

    /**
     * Build the vehicle capacity array from the vehicle's first-class columns.
     *
     * Returns a 4-element integer array:
     *   [weight_kg, volume_litres, pallets, parcels]
     *
     * payload_capacity        — existing column, weight in kg
     * payload_capacity_volume — new column, volume in m³ (stored as litres for VROOM)
     * payload_capacity_pallets / payload_capacity_parcels — new columns
     */
    protected static function buildVehicleCapacity(Vehicle $vehicle): array
    {
        return [
            (int) round((float) ($vehicle->payload_capacity ?? static::safeMeta($vehicle, 'max_weight_kg', 0))),
            (int) round((float) ($vehicle->payload_capacity_volume ?? static::safeMeta($vehicle, 'max_volume_m3', 0)) * 1000),
            (int) ($vehicle->payload_capacity_pallets ?? static::safeMeta($vehicle, 'max_pallets', 0)),
            (int) ($vehicle->payload_capacity_parcels ?? static::safeMeta($vehicle, 'max_parcels', 100)),
        ];
    }

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
     *   - amount:        multi-dimensional capacity demand [weight_kg, volume_litres, pallets, parcels]
     *   - priority:      orchestrator_priority (0–100, higher = more important)
     *   - description:   human-readable label for debugging
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

            // --- Capacity demand ---
            // Aggregated dynamically from payload entities; falls back to order meta.
            $job['amount'] = static::computePayloadDemand($order);

            // --- Time windows ---
            // Prefer explicit orchestrator time_window columns, fall back to scheduled_at
            if ($order->time_window_start && $order->time_window_end) {
                $job['time_windows'] = [[
                    $order->time_window_start->timestamp,
                    $order->time_window_end->timestamp,
                ]];
            } elseif ($order->scheduled_at) {
                $start               = $order->scheduled_at->timestamp;
                $end                 = $order->scheduled_at->copy()->addHours(4)->timestamp;
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
     * Build vehicles for vehicle-only allocation mode — no driver required.
     *
     * Uses vehicle.location as the start position. Skills and capacity are
     * read from the vehicle only. Used by the 'assign_vehicles' mode.
     */
    public static function buildVehiclesOnly(Collection $vehicles): array
    {
        return $vehicles->map(function (Vehicle $vehicle) {
            if (!$vehicle->location) {
                return null;
            }
            $entry = [
                'id'        => $vehicle->public_id,
                'driver_id' => null,
                'start'     => [$vehicle->location->getLng(), $vehicle->location->getLat()],
            ];
            if ($vehicle->return_to_depot && $vehicle->location) {
                $entry['end'] = [$vehicle->location->getLng(), $vehicle->location->getLat()];
            }
            $entry['capacity'] = static::buildVehicleCapacity($vehicle);
            if ($vehicle->max_tasks !== null && $vehicle->max_tasks > 0) {
                $entry['max_tasks'] = (int) $vehicle->max_tasks;
            }
            if ($vehicle->time_window_start && $vehicle->time_window_end) {
                $entry['time_window'] = [
                    $vehicle->time_window_start->timestamp,
                    $vehicle->time_window_end->timestamp,
                ];
            }
            $skills = static::resolveSkills($vehicle->skills ?? [], $vehicle->custom_fields ?? []);
            if (!empty($skills)) {
                $entry['skills'] = $skills;
            }

            return $entry;
        })->filter()->values()->toArray();
    }

    /**
     * Build vehicles for driver+vehicle allocation mode.
     *
     * Uses driver.location as the start position, falling back to vehicle.location.
     * Capacity is read from the vehicle. Time windows prefer the driver's explicit
     * window, then the driver's active shift, then the vehicle's window.
     */
    public static function buildVehicles(Collection $vehicles): array
    {
        return $vehicles->map(function (Vehicle $vehicle) {
            $driver = $vehicle->driver;
            // For vehicle-only mode, fall back to vehicle.location when no driver
            $startLocation = $driver?->location ?? $vehicle->location;
            if (!$startLocation) {
                return null;
            }
            // --- Start position ---
            $entry = [
                'id'        => $vehicle->public_id,
                'driver_id' => $driver?->public_id,
                'start'     => [$startLocation->getLng(), $startLocation->getLat()],
            ];
            // --- Return to depot ---
            if ($vehicle->return_to_depot && $startLocation) {
                $entry['end'] = [$startLocation->getLng(), $startLocation->getLat()];
            }

            // --- Multi-dimensional capacity ---
            $entry['capacity'] = static::buildVehicleCapacity($vehicle);

            // --- Max tasks ---
            if ($vehicle->max_tasks !== null && $vehicle->max_tasks > 0) {
                $entry['max_tasks'] = (int) $vehicle->max_tasks;
            }

            // --- Max travel time (seconds) ---
            $maxTravel = $driver?->max_travel_time ?? static::safeMeta($vehicle, 'max_travel_time_seconds');
            if ($maxTravel) {
                $entry['max_travel_time'] = (int) $maxTravel;
            }

            // --- Time window ---
            // Priority: driver.time_window_start/end columns → active shift → vehicle.time_window columns
            if ($driver?->time_window_start && $driver?->time_window_end) {
                $entry['time_window'] = [
                    $driver->time_window_start->timestamp,
                    $driver->time_window_end->timestamp,
                ];
            } else {
                $activeShift = $driver?->activeShiftFor(now());
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
                static::resolveSkills($driver?->skills ?? [], $driver?->custom_fields ?? [])
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
     * @param array $skills       String skill identifiers from the skills JSON column
     * @param array $customFields Custom fields array from HasCustomFields trait
     *
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
     * @deprecated use resolveSkills() instead
     */
    protected static function extractSkillsFromCustomFields(array $customFields): array
    {
        return static::resolveSkills([], $customFields);
    }
}
