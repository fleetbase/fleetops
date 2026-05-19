<?php

namespace Fleetbase\FleetOps\Orchestration\Engines;

use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Fleetbase\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VroomOrchestrationEngine.
 *
 * Self-contained VROOM integration for the FleetOps Orchestrator Workbench.
 * This engine does NOT depend on the optional `fleetbase/vroom` extension —
 * it communicates directly with the VROOM HTTP API using the same base URI
 * and API-key convention that the extension uses, so the two are compatible
 * when both are installed.
 *
 * Resolution order for configuration:
 *
 *   1. Company-level setting  — organization override
 *   2. System-level setting   — admin default
 *   3. Environment/config fallback
 *   4. No key                 — requests are sent without an api_key parameter
 *      (works for self-hosted VROOM instances that do not require auth)
 *
 *   Base URI and endpoint mode follow the same precedence, with endpoint mode
 *   controlling whether requests are posted to `/solve` or directly to the
 *   configured binary host.
 *
 *   Timeout:  `VROOM_TIMEOUT` env (default 30 s)
 *
 * The VROOM HTTP API endpoint used is `POST {base_uri}/solve`, matching the
 * verso-optim.com hosted service and the fleetbase/vroom extension.
 */
class VroomOrchestrationEngine implements OrchestrationEngineInterface
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
     * Constructs a VROOM-format payload from the normalized route tasks/vehicles
     * produced by OrchestrationPayloadBuilder, calls the VROOM HTTP API, and
     * maps the response back to the standard OrchestrationEngineInterface result
     * shape.
     *
     * @throws \RuntimeException if the VROOM API is unreachable or returns an error
     */
    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        if (($options['allocation_strategy'] ?? null) === 'capacity_only') {
            return $this->allocateCapacityOnly($orders, $vehicles, $options);
        }

        $tasks         = OrchestrationPayloadBuilder::buildRouteTasks($orders);
        $vroomVehicles = OrchestrationPayloadBuilder::buildVehicles($vehicles);

        [$routeTasks, $invalidTasks] = $this->partitionRouteTasks($tasks);

        if (empty($routeTasks)) {
            return $this->mergeInvalidTasks([
                'assignments' => [],
                'unassigned'  => [],
                'summary'     => [],
            ], $invalidTasks);
        }

        $jobIdReverse = [];
        $profile      = $options['profile'] ?? env('VROOM_PROFILE', 'driving');

        // Map normalized vehicle entries to VROOM vehicle objects.
        // The 'driver_id' key is not part of the VROOM spec — we carry it in
        // a description field and use it when mapping results back.
        $vroomPayload = [
            'jobs'      => [],
            'shipments' => [],
            'vehicles' => array_map(function (array $v) use ($profile) {
                $vehicle = [
                    'id'          => crc32($v['id']), // VROOM requires integer IDs
                    'description' => json_encode(['vehicle_id' => $v['id'], 'driver_id' => $v['driver_id']]),
                    'start'       => $v['start'],
                    'capacity'    => $v['capacity'],
                ];
                if ($profile) {
                    $vehicle['profile'] = $profile;
                }
                if (isset($v['end'])) {
                    $vehicle['end'] = $v['end'];
                }
                if (isset($v['time_window'])) {
                    $vehicle['time_window'] = $v['time_window'];
                }
                if (isset($v['skills'])) {
                    $vehicle['skills'] = $v['skills'];
                }
                if (isset($v['max_tasks'])) {
                    $vehicle['max_tasks'] = $v['max_tasks'];
                }
                if (isset($v['max_travel_time'])) {
                    $vehicle['max_travel_time'] = $v['max_travel_time'];
                }

                return $vehicle;
            }, $vroomVehicles),
            'options' => [
                'g' => $options['geometry'] ?? false,
            ],
        ];

        foreach ($routeTasks as $task) {
            if (count($task['stops']) > 1) {
                $shipment = $this->mapTaskToShipment($task, $jobIdReverse);
                if ($shipment) {
                    $vroomPayload['shipments'][] = $shipment;
                }

                continue;
            }

            $job = $this->mapTaskToVroomJob($task, $jobIdReverse);
            if ($job) {
                $vroomPayload['jobs'][] = $job;
            }
        }

        if (empty($vroomPayload['jobs'])) {
            unset($vroomPayload['jobs']);
        }
        if (empty($vroomPayload['shipments'])) {
            unset($vroomPayload['shipments']);
        }

        $result = $this->callVroom($vroomPayload);

        return $this->mergeInvalidTasks(
            $this->mapVroomResponse($result, $jobIdReverse),
            $invalidTasks
        );
    }

    protected function allocateCapacityOnly(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $tasks         = OrchestrationPayloadBuilder::buildCapacityTasks($orders);
        $vroomVehicles = OrchestrationPayloadBuilder::buildCapacityVehicles($vehicles);

        [$capacityTasks, $invalidTasks] = $this->partitionRouteTasks($tasks);

        if (empty($capacityTasks)) {
            return $this->mergeInvalidTasks([
                'assignments' => [],
                'unassigned'  => [],
                'summary'     => [
                    'engine'              => 'vroom',
                    'allocation_strategy' => 'capacity_only',
                ],
            ], $invalidTasks);
        }

        $jobIdReverse = [];
        $vroomPayload = $this->buildCapacityOnlyPayload($capacityTasks, $vroomVehicles, $options, $jobIdReverse);

        if (empty($vroomPayload['vehicles'])) {
            $vehiclePacking   = $this->resolveVehiclePacking($options);
            $vehicleFixedCost = $this->resolveVehicleFixedCost($options);

            return $this->mergeInvalidTasks([
                'assignments' => [],
                'unassigned'  => array_column($capacityTasks, 'id'),
                'summary'     => [
                    'engine'              => 'vroom',
                    'allocation_strategy' => 'capacity_only',
                    'vehicle_packing'     => $vehiclePacking,
                    'vehicle_fixed_cost'  => $vehiclePacking === 'minimize_vehicles' ? $vehicleFixedCost : null,
                    'assigned'            => 0,
                    'unassigned'          => count($capacityTasks),
                    'unassigned_reasons'  => array_map(fn (array $task) => [
                        'order_id' => $task['id'],
                        'reason'   => 'no_available_vehicle',
                    ], $capacityTasks),
                ],
            ], $invalidTasks);
        }

        $result = $this->mapVroomResponse($this->callVroom($vroomPayload), $jobIdReverse);

        $result['summary'] = array_merge($result['summary'] ?? [], [
            'engine'              => 'vroom',
            'allocation_strategy' => 'capacity_only',
            'vehicle_packing'     => $this->resolveVehiclePacking($options),
            'vehicle_fixed_cost'  => $this->resolveVehiclePacking($options) === 'minimize_vehicles' ? $this->resolveVehicleFixedCost($options) : null,
        ]);

        return $this->mergeInvalidTasks($result, $invalidTasks);
    }

    protected function buildCapacityOnlyPayload(array $capacityTasks, array $vroomVehicles, array $options, array &$jobIdReverse): array
    {
        $matrixSize      = count($capacityTasks) + 1;
        $profile         = $options['profile'] ?? 'capacity_only';
        $respectCapacity = (bool) ($options['respect_capacity'] ?? true);
        $respectSkills   = (bool) ($options['respect_skills'] ?? true);
        $vehiclePacking  = $this->resolveVehiclePacking($options);
        $fixedCost       = $this->resolveVehicleFixedCost($options);

        $vroomPayload = [
            'jobs'     => [],
            'vehicles' => array_map(function (array $v) use ($profile, $respectCapacity, $respectSkills, $vehiclePacking, $fixedCost) {
                $vehicle = [
                    'id'          => crc32($v['id']),
                    'description' => json_encode(['vehicle_id' => $v['id'], 'driver_id' => $v['driver_id'] ?? null]),
                    'profile'     => $profile,
                    'start_index' => 0,
                ];

                if ($vehiclePacking === 'minimize_vehicles') {
                    $vehicle['costs'] = ['fixed' => $fixedCost];
                }

                if ($respectCapacity) {
                    $vehicle['capacity'] = $v['capacity'];
                }
                if ($respectSkills && isset($v['skills'])) {
                    $vehicle['skills'] = $v['skills'];
                }

                foreach (['time_window', 'max_tasks', 'max_travel_time'] as $key) {
                    if (isset($v[$key])) {
                        $vehicle[$key] = $v[$key];
                    }
                }

                return $vehicle;
            }, $vroomVehicles),
            'matrices' => [
                $profile => [
                    'durations' => $this->buildUniformMatrix($matrixSize),
                    'distances' => $this->buildUniformMatrix($matrixSize),
                ],
            ],
            'options' => [
                'g' => false,
            ],
        ];

        foreach ($capacityTasks as $index => $task) {
            $orderId              = $task['id'];
            $intId                = crc32($orderId);
            $jobIdReverse[$intId] = $orderId;

            $job = [
                'id'             => $intId,
                'location_index' => $index + 1,
                'description'    => $task['description'] ?? $orderId,
            ];

            if ($respectCapacity) {
                $job['delivery'] = $task['amount'] ?? [0, 0, 0, 0];
            }
            if ($respectSkills && isset($task['skills'])) {
                $job['skills'] = $task['skills'];
            }

            $this->copyTaskFields($task, $job, ['priority']);
            $vroomPayload['jobs'][] = $job;
        }

        return $vroomPayload;
    }

    protected function resolveVehiclePacking(array $options): string
    {
        $packing = strtolower((string) ($options['vehicle_packing'] ?? 'minimize_vehicles'));

        return in_array($packing, ['minimize_vehicles', 'balanced', 'none'], true)
            ? $packing
            : 'minimize_vehicles';
    }

    protected function resolveVehicleFixedCost(array $options): int
    {
        return max(0, (int) ($options['vehicle_fixed_cost'] ?? 100000));
    }

    protected function buildUniformMatrix(int $size): array
    {
        $matrix = [];

        for ($row = 0; $row < $size; $row++) {
            $matrix[$row] = [];
            for ($column = 0; $column < $size; $column++) {
                $matrix[$row][$column] = $row === $column ? 0 : 1;
            }
        }

        return $matrix;
    }

    protected function partitionRouteTasks(array $tasks): array
    {
        $routeTasks   = [];
        $invalidTasks = [];

        foreach ($tasks as $task) {
            if (!empty($task['invalid'])) {
                $invalidTasks[] = $task;
                continue;
            }

            $routeTasks[] = $task;
        }

        return [$routeTasks, $invalidTasks];
    }

    /**
     * Map a VROOM API response to the standard OrchestrationEngineInterface shape.
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
                if (!in_array($step['type'], ['job', 'delivery'], true)) {
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
            'unassigned'  => array_values(array_unique($unassigned)),
            'summary'     => $vroomResult['summary'] ?? [],
        ];
    }

    protected function mapTaskToVroomJob(array $task, array &$jobIdReverse): ?array
    {
        $stop = $task['stops'][0] ?? null;
        if (!$stop || empty($stop['location'])) {
            return null;
        }

        $orderId              = $task['id'];
        $intId                = crc32($orderId);
        $jobIdReverse[$intId] = $orderId;

        $vroomJob = [
            'id'          => $intId,
            'location'    => $stop['location'],
            'description' => $task['description'] ?? $orderId,
        ];

        $this->copyTaskFields($task, $vroomJob, ['service', 'amount', 'time_windows', 'skills', 'priority']);

        return $vroomJob;
    }

    protected function mapTaskToShipment(array $task, array &$jobIdReverse): ?array
    {
        $stops = $task['stops'] ?? [];
        $first = $stops[0] ?? null;
        $last  = $stops[count($stops) - 1] ?? null;

        if (!$first || !$last || empty($first['location']) || empty($last['location'])) {
            return null;
        }

        $orderId    = $task['id'];
        $pickupId   = crc32($orderId . ':pickup');
        $deliveryId = crc32($orderId . ':delivery');

        // We only create one FleetOps assignment per order, so route pickup
        // steps are ignored while delivery and unassigned entries still map
        // back to the order public_id.
        $jobIdReverse[$pickupId]   = $orderId;
        $jobIdReverse[$deliveryId] = $orderId;

        $shipment = [
            'pickup' => [
                'id'          => $pickupId,
                'location'    => $first['location'],
                'description' => ($task['description'] ?? $orderId) . ' pickup',
            ],
            'delivery' => [
                'id'          => $deliveryId,
                'location'    => $last['location'],
                'description' => ($task['description'] ?? $orderId) . ' delivery',
            ],
        ];

        if (array_key_exists('service', $task)) {
            $shipment['delivery']['service'] = $task['service'];
        }
        if (array_key_exists('time_windows', $task)) {
            $shipment['delivery']['time_windows'] = $task['time_windows'];
        }

        $this->copyTaskFields($task, $shipment, ['amount', 'skills', 'priority']);

        return $shipment;
    }

    protected function copyTaskFields(array $task, array &$target, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $task)) {
                $target[$key] = $task[$key];
            }
        }
    }

    protected function mergeInvalidTasks(array $result, array $invalidTasks): array
    {
        if (empty($invalidTasks)) {
            return $result;
        }

        $invalid = array_map(fn (array $task) => [
            'order_id' => $task['id'],
            'reason'   => $task['reason'] ?? 'Order has invalid route coordinates.',
        ], $invalidTasks);

        $result['unassigned'] = array_values(array_unique(array_merge(
            $result['unassigned'] ?? [],
            array_column($invalid, 'order_id')
        )));

        $summary             = $result['summary'] ?? [];
        $summary['invalid']  = array_values(array_merge($summary['invalid'] ?? [], $invalid));
        $result['summary']   = $summary;

        return $result;
    }

    protected function callVroom(array $vroomPayload): array
    {
        // ── Resolve connection config ─────────────────────────────────────────
        $baseUri      = $this->resolveVroomBaseUri();
        $endpointMode = $this->resolveVroomEndpointMode();
        $timeout      = (int) env('VROOM_TIMEOUT', 30);

        $apiKey = $this->resolveVroomApiKey();

        // Build the solve URL — append api_key as query param when present.
        $solveUrl = rtrim($baseUri, '/');
        if ($endpointMode !== 'binary') {
            $solveUrl .= '/solve';
        }
        if ($apiKey) {
            $solveUrl .= '?api_key=' . urlencode($apiKey);
        }

        // ── Call VROOM ────────────────────────────────────────────────────────
        try {
            $response = Http::timeout($timeout)->post($solveUrl, $vroomPayload);
        } catch (\Exception $e) {
            Log::error('[VroomOrchestrationEngine] HTTP request failed: ' . $e->getMessage());
            throw new \RuntimeException('VROOM allocation engine is unavailable: ' . $e->getMessage() . ' — ensure VROOM_HOST is reachable or switch to the built-in greedy engine.', 0, $e);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('VROOM returned an error: HTTP ' . $response->status() . ' — ' . $response->body());
        }

        return $response->json();
    }

    protected function resolveVroomBaseUri(): string
    {
        return $this->resolveVroomSetting('api_host', config('vroom.base_uri', env('VROOM_HOST', 'https://api.verso-optim.com/vrp/v1')));
    }

    protected function resolveVroomApiKey(): ?string
    {
        return $this->resolveVroomSetting('api_key', env('VROOM_API_KEY'));
    }

    protected function resolveVroomEndpointMode(): string
    {
        return $this->resolveVroomSetting('endpoint_mode', config('vroom.endpoint_mode', env('VROOM_ENDPOINT_MODE', 'saas')));
    }

    protected function resolveVroomSetting(string $key, $default = null)
    {
        try {
            $organizationValue = data_get(Setting::lookupCompany('vroom', []), $key);
            if ($this->hasConfiguredValue($organizationValue)) {
                return $organizationValue;
            }

            $systemValue = data_get(Setting::lookup('vroom', []), $key);
            if ($this->hasConfiguredValue($systemValue)) {
                return $systemValue;
            }
        } catch (\Throwable) {
            // Setting table may not exist in minimal installs — ignore
        }

        return $default;
    }

    protected function hasConfiguredValue($value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }
}
