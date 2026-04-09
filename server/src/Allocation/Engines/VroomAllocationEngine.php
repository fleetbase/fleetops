<?php

namespace Fleetbase\FleetOps\Allocation\Engines;

use Fleetbase\FleetOps\Allocation\Contracts\AllocationEngineInterface;
use Fleetbase\FleetOps\Allocation\Support\AllocationPayloadBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VroomAllocationEngine.
 *
 * Implements AllocationEngineInterface using the VROOM open-source vehicle
 * routing engine (https://github.com/VROOM-Project/vroom).
 *
 * VROOM is already used by FleetOps for route optimization. This engine
 * reuses the same VROOM endpoint but constructs a Vehicle Routing Problem
 * (VRP) payload instead of a Travelling Salesman Problem (TSP) payload,
 * enabling multi-vehicle assignment with capacity, skill, and time-window
 * constraints.
 *
 * Configuration:
 *   VROOM_HOST — base URL of the VROOM server (default: http://localhost:3000)
 *   VROOM_TIMEOUT — HTTP timeout in seconds (default: 30)
 */
class VroomAllocationEngine implements AllocationEngineInterface
{
    public function getName(): string
    {
        return 'VROOM';
    }

    public function getIdentifier(): string
    {
        return 'vroom';
    }

    /**
     * Run the VROOM VRP solver.
     *
     * Constructs a VROOM-format payload from the normalized jobs/vehicles
     * produced by AllocationPayloadBuilder, calls the VROOM HTTP API, and
     * maps the response back to the standard AllocationEngineInterface result
     * shape.
     *
     * @throws \RuntimeException if the VROOM API returns an error
     */
    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $jobs          = AllocationPayloadBuilder::buildJobs($orders);
        $vroomVehicles = AllocationPayloadBuilder::buildVehicles($vehicles);

        if (empty($jobs)) {
            return ['assignments' => [], 'unassigned' => [], 'summary' => []];
        }

        // Map normalized vehicle entries to VROOM vehicle objects.
        // The 'driver_id' key is not part of the VROOM spec — we carry it in
        // a description field and use it when mapping results back.
        $vroomPayload = [
            'jobs'     => $jobs,
            'vehicles' => array_map(function (array $v) {
                $vehicle = [
                    'id'          => crc32($v['id']), // VROOM requires integer IDs
                    'description' => json_encode(['vehicle_id' => $v['id'], 'driver_id' => $v['driver_id']]),
                    'start'       => $v['start'],
                    'capacity'    => $v['capacity'],
                ];
                if (isset($v['time_window'])) {
                    $vehicle['time_window'] = $v['time_window'];
                }
                if (isset($v['skills'])) {
                    $vehicle['skills'] = $v['skills'];
                }

                return $vehicle;
            }, $vroomVehicles),
            'options' => [
                'g' => $options['geometry'] ?? false,
            ],
        ];

        // Map job IDs to integer IDs for VROOM, keeping a reverse lookup
        $jobIdMap     = [];
        $jobIdReverse = [];
        foreach ($vroomPayload['jobs'] as &$job) {
            $intId                = crc32($job['id']);
            $jobIdReverse[$intId] = $job['id'];
            $job['id']            = $intId;
            $jobIdMap[$job['id']] = true;
        }
        unset($job);

        $host    = config('fleetops.vroom.host', env('VROOM_HOST', 'http://localhost:3000'));
        $timeout = (int) config('fleetops.vroom.timeout', env('VROOM_TIMEOUT', 30));

        try {
            $response = Http::timeout($timeout)
                ->post("{$host}/", $vroomPayload);
        } catch (\Exception $e) {
            Log::error('[VroomAllocationEngine] HTTP request failed: ' . $e->getMessage());
            throw new \RuntimeException('VROOM allocation engine is unavailable: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('VROOM returned an error: ' . $response->status() . ' — ' . $response->body());
        }

        $result = $response->json();

        return $this->mapVroomResponse($result, $jobIdReverse);
    }

    /**
     * Map a VROOM API response to the standard AllocationEngineInterface shape.
     *
     * @param array $jobIdReverse Map of VROOM integer job ID → order public_id
     */
    protected function mapVroomResponse(array $vroomResult, array $jobIdReverse): array
    {
        $assignments = [];

        foreach ($vroomResult['routes'] ?? [] as $route) {
            $vehicleDesc = json_decode($route['description'] ?? '{}', true);
            $vehicleId   = $vehicleDesc['vehicle_id'] ?? null;
            $driverId    = $vehicleDesc['driver_id'] ?? null;
            $sequence    = 0;

            foreach ($route['steps'] ?? [] as $step) {
                if ($step['type'] !== 'job') {
                    continue;
                }
                $orderId = $jobIdReverse[$step['id']] ?? null;
                if (!$orderId) {
                    continue;
                }
                $assignments[] = [
                    'order_id'   => $orderId,
                    'vehicle_id' => $vehicleId,
                    'driver_id'  => $driverId,
                    'sequence'   => ++$sequence,
                    'arrival'    => $step['arrival'] ?? null,
                    'duration'   => $step['duration'] ?? null,
                    'distance'   => $step['distance'] ?? null,
                ];
            }
        }

        $unassigned = array_map(
            fn ($u) => $jobIdReverse[$u['id']] ?? $u['id'],
            $vroomResult['unassigned'] ?? []
        );

        return [
            'assignments' => $assignments,
            'unassigned'  => array_values($unassigned),
            'summary'     => $vroomResult['summary'] ?? [],
        ];
    }
}
