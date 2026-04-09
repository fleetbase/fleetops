<?php

namespace Fleetbase\FleetOps\Orchestration\Engines;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * DriverAssignmentEngine.
 *
 * Greedy shift-aware engine for matching available drivers to vehicles that
 * have planned orders (vehicle_assigned_uuid set, driver_assigned_uuid null).
 *
 * Matching priority:
 *   1. Driver is online and on an active shift
 *   2. Driver skills satisfy the required skills of the vehicle's planned orders
 *   3. Driver is geographically closest to the vehicle's current location
 *
 * This engine does not use VROOM — it is a deterministic greedy pass.
 * For V2, a VROOM-based multi-depot driver assignment can replace this.
 */
class DriverAssignmentEngine
{
    /**
     * Assign available drivers to vehicles with planned orders.
     *
     * @param Collection $orders   Orders with vehicle_assigned_uuid set but no driver
     * @param Collection $vehicles Vehicles to assign drivers to
     * @param array      $options  Options: require_active_shift (bool, default true)
     *
     * @return array{assignments: array, unassigned: array, summary: array}
     */
    public function assign(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $requireActiveShift = $options['require_active_shift'] ?? true;
        $respectSkills      = $options['respect_skills'] ?? true;

        // Collect available drivers (online, with a vehicle or without)
        $companyUuid      = $orders->first()?->company_uuid;
        $availableDrivers = Driver::where('company_uuid', $companyUuid)
            ->where('online', true)
            ->whereNull('vehicle_uuid') // Only unassigned drivers
            ->with(['scheduleItems'])
            ->get();

        // Filter to drivers on an active shift if required
        if ($requireActiveShift) {
            $availableDrivers = $availableDrivers->filter(function (Driver $driver) {
                return $driver->activeShiftFor(now()) !== null;
            });
        }

        if ($availableDrivers->isEmpty()) {
            return [
                'assignments' => [],
                'unassigned'  => $vehicles->pluck('public_id')->toArray(),
                'summary'     => ['message' => 'No available drivers found.'],
            ];
        }

        $assignments        = [];
        $assignedDrivers    = [];
        $unassignedVehicles = [];

        // Group orders by vehicle to determine required skills per vehicle
        $ordersByVehicle = $orders->groupBy('vehicle_assigned_uuid');

        foreach ($vehicles as $vehicle) {
            // Determine required skills from the vehicle's planned orders
            $vehicleOrders   = $ordersByVehicle->get($vehicle->uuid, collect());
            $requiredSkills  = $this->aggregateRequiredSkills($vehicleOrders);

            // Find the best available driver for this vehicle
            $bestDriver = $this->findBestDriver(
                $vehicle,
                $availableDrivers->reject(fn ($d) => in_array($d->uuid, $assignedDrivers)),
                $requiredSkills,
                $respectSkills
            );

            if (!$bestDriver) {
                $unassignedVehicles[] = $vehicle->public_id;
                continue;
            }

            $assignedDrivers[] = $bestDriver->uuid;

            // Build one assignment entry per order on this vehicle
            foreach ($vehicleOrders as $order) {
                $assignments[] = [
                    'order_id'   => $order->public_id,
                    'vehicle_id' => $vehicle->public_id,
                    'driver_id'  => $bestDriver->public_id,
                    'sequence'   => null, // Sequence already set from Phase 1
                ];
            }
        }

        return [
            'assignments' => $assignments,
            'unassigned'  => $unassignedVehicles,
            'summary'     => [
                'drivers_assigned'    => count($assignedDrivers),
                'vehicles_unassigned' => count($unassignedVehicles),
            ],
        ];
    }

    /**
     * Find the best available driver for a given vehicle.
     *
     * Scoring:
     *   - Skills match: +100 per matching skill
     *   - Active shift: +50
     *   - Proximity: inversely proportional to distance (max +50)
     */
    protected function findBestDriver(
        Vehicle $vehicle,
        Collection $availableDrivers,
        array $requiredSkills,
        bool $respectSkills,
    ): ?Driver {
        if ($availableDrivers->isEmpty()) {
            return null;
        }

        $vehicleLat = $vehicle->location?->getLat();
        $vehicleLng = $vehicle->location?->getLng();

        $scored = $availableDrivers->map(function (Driver $driver) use (
            $requiredSkills, $respectSkills, $vehicleLat, $vehicleLng
        ) {
            $score = 0;

            // Skills check
            if ($respectSkills && !empty($requiredSkills)) {
                $driverSkills = $driver->skills ?? [];
                $matchCount   = count(array_intersect($requiredSkills, $driverSkills));
                if ($matchCount < count($requiredSkills)) {
                    // Driver does not meet all required skills — disqualify
                    return null;
                }
                $score += $matchCount * 100;
            }

            // Active shift bonus
            if ($driver->activeShiftFor(now()) !== null) {
                $score += 50;
            }

            // Proximity bonus (only if vehicle has a known location)
            if ($vehicleLat && $vehicleLng && $driver->location) {
                $distance = $this->haversineDistance(
                    $vehicleLat, $vehicleLng,
                    $driver->location->getLat(), $driver->location->getLng()
                );
                // Closer = higher score; cap at 50 points for distance ≤ 1 km
                $proximityScore = max(0, 50 - ($distance / 1000));
                $score += $proximityScore;
            }

            return ['driver' => $driver, 'score' => $score];
        })->filter()->sortByDesc('score');

        return $scored->first()['driver'] ?? null;
    }

    /**
     * Aggregate the unique required skills from a collection of orders.
     */
    protected function aggregateRequiredSkills(Collection $orders): array
    {
        return $orders->flatMap(fn ($o) => $o->required_skills ?? [])->unique()->values()->toArray();
    }

    /**
     * Calculate the Haversine distance in metres between two lat/lng points.
     */
    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // metres
        $dLat        = deg2rad($lat2 - $lat1);
        $dLng        = deg2rad($lng2 - $lng1);
        $a           = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
