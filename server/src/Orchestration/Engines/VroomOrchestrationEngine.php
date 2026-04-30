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
     * Constructs a VROOM-format payload from the normalized jobs/vehicles
     * produced by OrchestrationPayloadBuilder, calls the VROOM HTTP API, and
     * maps the response back to the standard OrchestrationEngineInterface result
     * shape.
     *
     * @throws \RuntimeException if the VROOM API is unreachable or returns an error
     */
    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $jobs          = OrchestrationPayloadBuilder::buildJobs($orders);
        $vroomVehicles = OrchestrationPayloadBuilder::buildVehicles($vehicles);

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

        // ── Resolve connection config ─────────────────────────────────────────
        $baseUri = $this->resolveVroomBaseUri();
        $endpointMode = $this->resolveVroomEndpointMode();
        $timeout = (int) env('VROOM_TIMEOUT', 30);

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

        $result = $response->json();

        return $this->mapVroomResponse($result, $jobIdReverse);
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
