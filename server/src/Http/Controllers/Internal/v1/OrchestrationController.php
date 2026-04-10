<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Orchestrator\Order as OrchestratorOrderResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OrchestrationController.
 *
 * HTTP interface for the Orchestrator Workbench.
 *
 * Responsibilities:
 *   - Serving orders for the workbench (with custom field values)
 *   - Running orchestration phases (assign_vehicles, assign_drivers, optimize, allocate)
 *   - Committing a proposed plan to Manifests and ManifestStops
 *   - Listing available orchestration engines
 *   - Providing order-config custom field definitions for card configuration
 *   - Importing orders from parsed CSV/Excel data
 */
class OrchestrationController extends Controller
{
    public function __construct(protected OrchestrationEngineRegistry $registry)
    {
    }

    /**
     * Return orders for the Orchestrator Workbench.
     *
     * This endpoint uses the dedicated OrchestratorOrderResource which includes
     * custom_field_values — unlike the lightweight Index/Order resource used by
     * the tabular orders view, which intentionally omits them for performance.
     *
     * GET /int/v1/fleet-ops/orchestrator/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $query = Order::where('company_uuid', $companyUuid)->whereIn('status', ['created', 'dispatched', 'started']);

        $query->whereHas('payload', function ($payloadQuery) {
            $payloadQuery->where(function ($q) {
                $q->whereHas('waypoints', function ($w) {
                    $w->whereNotNull('waypoints.uuid');
                });
                $q->orWhereHas('pickup', function ($p) {
                    $p->whereNotNull('places.uuid');
                });
                $q->orWhereHas('dropoff', function ($d) {
                    $d->whereNotNull('places.uuid');
                });
            });
        });

        $query->whereHas('trackingNumber', function ($q) {
            $q->select('uuid');
        });

        $query->whereHas('trackingStatuses', function ($q) {
            $q->select('uuid');
        });

        if ($request->boolean('unassigned')) {
            $query->whereNull('vehicle_assigned_uuid');
        }

        $query->with([
            'payload.entities',
            'payload.waypoints',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'trackingNumber',
            'trackingStatuses',
            'driverAssigned' => function ($query) {
                $query->without(['jobs', 'currentJob']);
            },
            'vehicleAssigned' => function ($query) {
                $query->without(['fleets', 'vendor']);
            },
            'customer',
            'facilitator',
            'customFieldValues.customField',
        ]);

        $limit  = min((int) $request->input('limit', 500), 1000);
        $orders = $query->limit($limit)->get();

        return response()->json([
            'orders' => OrchestratorOrderResource::collection($orders)->resolve(),
        ]);
    }

    /**
     * Run an orchestration phase for the given mode.
     *
     * POST /int/v1/fleet-ops/orchestrator/run
     */
    public function run(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $mode        = $request->input('mode', 'assign_vehicles');
        $orderIds    = $request->input('order_ids', []);
        $vehicleIds  = $request->input('vehicle_ids', []);
        $driverIds   = $request->input('driver_ids', []);
        $options     = $request->input('options', []);

        // ── Resolve orders ────────────────────────────────────────────────────
        $ordersQuery = Order::where('company_uuid', $companyUuid)
            ->whereIn('status', ['created', 'dispatched', 'started'])
            ->with(['payload.dropoff', 'payload.pickup', 'payload.waypoints', 'payload.waypointMarkers', 'payload.entities']);

        if ($mode === 'assign_vehicles' || $mode === 'allocate') {
            $ordersQuery->whereNull('vehicle_assigned_uuid');
        } elseif ($mode === 'optimize') {
            $ordersQuery->whereNotNull('vehicle_assigned_uuid');
        } elseif ($mode === 'assign_drivers') {
            $ordersQuery->whereNotNull('vehicle_assigned_uuid')
                ->whereNull('driver_assigned_uuid');
        }

        if (!empty($orderIds)) {
            $ordersQuery->whereIn('public_id', $orderIds);
        }
        $orders = $ordersQuery->get();

        // ── Resolve vehicles ──────────────────────────────────────────────────
        $vehiclesQuery = Vehicle::where('company_uuid', $companyUuid)
            ->with(['driver' => fn ($q) => $q->with(['scheduleItems'])]);

        if (!empty($vehicleIds)) {
            $vehiclesQuery->whereIn('public_id', $vehicleIds);
        } elseif (!empty($driverIds)) {
            $vehiclesQuery->whereHas('driver', fn ($q) => $q->whereIn('public_id', $driverIds));
        }

        // assign_vehicles and assign_drivers include vehicles without online drivers
        if ($mode === 'assign_vehicles' || $mode === 'assign_drivers') {
            $vehicles = $vehiclesQuery->get();
        } else {
            // Legacy allocate mode requires an online driver
            $vehicles = $vehiclesQuery->get()->filter(fn ($v) => $v->driver !== null);
        }

        if ($orders->isEmpty()) {
            return response()->json([
                'message'     => 'No orders found for the given criteria.',
                'assignments' => [],
                'unassigned'  => [],
            ], 200);
        }

        if ($vehicles->isEmpty()) {
            return response()->json([
                'message'     => 'No available vehicles found.',
                'assignments' => [],
                'unassigned'  => $orders->pluck('public_id'),
            ], 200);
        }

        // ── Run engine ────────────────────────────────────────────────────────
        $engineId = $mode === 'assign_drivers'
            ? 'driver_assignment'
            : ($request->input('options.engine') ?? Setting::lookup('fleetops.orchestrator_engine', 'greedy'));

        try {
            if ($mode === 'assign_drivers') {
                $engine = new DriverAssignmentEngine();
                $result = $engine->assign($orders, $vehicles, $options);
            } else {
                $engine = $this->registry->resolve($engineId);
                $result = $engine->allocate($orders, $vehicles, $options);
            }
        } catch (\RuntimeException $e) {
            // Engine is unavailable (e.g. VROOM not reachable).
            // Return a structured JSON 503 so the frontend can display a
            // user-friendly message instead of an unhandled exception page.
            return response()->json([
                'error'  => $e->getMessage(),
                'hint'   => 'If you are using the VROOM engine, ensure the VROOM service is running and VROOM_HOST is configured correctly. Alternatively, switch to the built-in "greedy" engine in Orchestrator Settings.',
                'engine' => $engineId,
            ], 503);
        }

        return response()->json($result);
    }

    /**
     * Preview an orchestration run without committing any assignments.
     *
     * GET /int/v1/fleet-ops/orchestrator/preview
     */
    public function preview(Request $request): JsonResponse
    {
        return $this->run($request);
    }

    /**
     * Commit an orchestration plan — creates Manifests and ManifestStops.
     *
     * Does NOT trigger dispatch or update order status. That is the
     * responsibility of the operational flow (driver actions / dispatcher).
     *
     * POST /int/v1/fleet-ops/orchestrator/commit
     */
    public function commit(Request $request): JsonResponse
    {
        $assignments   = $request->input('assignments', []);
        $scheduledDate = $request->input('scheduled_date', now()->toDateString());
        $companyUuid   = session('company');

        if (empty($assignments)) {
            return response()->json(['error' => 'No assignments provided.'], 422);
        }

        $committed = [];
        $failed    = [];
        $manifests = [];

        DB::beginTransaction();
        try {
            // Group assignments by vehicle_id
            $byVehicle = [];
            foreach ($assignments as $assignment) {
                $vehicleId = $assignment['vehicle_id'] ?? null;
                if (!$vehicleId) {
                    $failed[] = $assignment['order_id'] ?? 'unknown';
                    continue;
                }
                $byVehicle[$vehicleId][] = $assignment;
            }

            foreach ($byVehicle as $vehiclePublicId => $vehicleAssignments) {
                $vehicle = Vehicle::where('public_id', $vehiclePublicId)->first();
                if (!$vehicle) {
                    foreach ($vehicleAssignments as $a) {
                        $failed[] = $a['order_id'];
                    }
                    continue;
                }

                // Driver is optional (vehicle-only assignment)
                $driverPublicId = $vehicleAssignments[0]['driver_id'] ?? null;
                $driver         = $driverPublicId
                    ? Driver::where('public_id', $driverPublicId)->first()
                    : null;

                $totalDistance = (int) array_sum(array_column($vehicleAssignments, 'distance'));
                $totalDuration = (int) array_sum(array_column($vehicleAssignments, 'duration'));

                // Create Manifest
                $manifest = Manifest::create([
                    'company_uuid'     => $companyUuid,
                    'vehicle_uuid'     => $vehicle->uuid,
                    'driver_uuid'      => $driver?->uuid,
                    'status'           => 'draft',
                    'scheduled_date'   => $scheduledDate,
                    'total_distance_m' => $totalDistance,
                    'total_duration_s' => $totalDuration,
                    'stop_count'       => count($vehicleAssignments),
                ]);

                // Sort stops by sequence
                usort($vehicleAssignments, fn ($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

                foreach ($vehicleAssignments as $idx => $assignment) {
                    $order = Order::where('public_id', $assignment['order_id'])->first();
                    if (!$order) {
                        $failed[] = $assignment['order_id'];
                        continue;
                    }

                    $placeUuid = $order->payload?->dropoff?->uuid ?? null;

                    ManifestStop::create([
                        'manifest_uuid'        => $manifest->uuid,
                        'order_uuid'           => $order->uuid,
                        'place_uuid'           => $placeUuid,
                        'sequence'             => (int) ($assignment['sequence'] ?? ($idx + 1)),
                        'status'               => 'pending',
                        'estimated_arrival'    => isset($assignment['arrival'])
                            ? Carbon::createFromTimestamp($assignment['arrival'])
                            : null,
                        'distance_from_prev_m' => (int) ($assignment['distance'] ?? 0),
                        'duration_from_prev_s' => (int) ($assignment['duration'] ?? 0),
                    ]);

                    // Update order assignments
                    $order->vehicle_assigned_uuid = $vehicle->uuid;
                    $order->manifest_uuid         = $manifest->uuid;
                    if ($driver) {
                        $order->driver_assigned_uuid = $driver->uuid;
                    }
                    if (isset($assignment['sequence'])) {
                        $order->is_route_optimized = true;
                    }
                    $order->save();

                    // Update waypoint sequence if provided
                    if (!empty($assignment['waypoint_sequence']) && $order->payload) {
                        foreach ($assignment['waypoint_sequence'] as $seq => $waypointId) {
                            DB::table('waypoints')
                                ->where('payload_uuid', $order->payload_uuid)
                                ->where('public_id', $waypointId)
                                ->update(['order' => $seq]);
                        }
                    }

                    $committed[] = $assignment['order_id'];
                }

                $manifests[] = $manifest->public_id;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Commit failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'committed' => $committed,
            'failed'    => $failed,
            'manifests' => $manifests,
        ]);
    }

    /**
     * Return available orchestration engines.
     *
     * GET /int/v1/fleet-ops/orchestrator/engines
     */
    public function engines(): JsonResponse
    {
        return response()->json([
            'engines' => $this->registry->available(),
        ]);
    }

    /**
     * Return all active Order Configs with their custom field definitions.
     * Used by the Orchestrator Settings UI for configurable card fields.
     *
     * GET /int/v1/fleet-ops/orchestrator/order-config-fields
     */
    public function orderConfigFields(): JsonResponse
    {
        $companyUuid = session('company');

        $configs = OrderConfig::where('company_uuid', $companyUuid)
            ->with('customFields')
            ->get(['uuid', 'public_id', 'name', 'key'])
            ->map(function ($config) {
                // customFields is a morphMany on subject_uuid/subject_type.
                // If the eager load returned nothing (e.g. subject_type mismatch),
                // fall back to a direct query by subject_uuid.
                $customFields = $config->customFields;
                if ($customFields->isEmpty()) {
                    $customFields = \Fleetbase\Models\CustomField::where('subject_uuid', $config->uuid)
                        ->orderBy('order')
                        ->get();
                }

                $fields = $customFields
                    ->map(fn ($field) => [
                        'key'      => $field->name ?? Str::slug($field->label ?? '', '_'),
                        'label'    => $field->label ?? $field->name ?? '',
                        'type'     => $field->type ?? 'text',
                        'required' => (bool) ($field->required ?? false),
                    ])
                    ->values();

                return [
                    'id'     => $config->public_id,
                    'uuid'   => $config->uuid,
                    'name'   => $config->name,
                    'key'    => $config->key,
                    'fields' => $fields,
                ];
            });

        // Exclude configs that have no custom fields at all
        $configs = $configs->filter(fn ($config) => count($config['fields']) > 0)->values();

        return response()->json(['configs' => $configs]);
    }

    /**
     * Import orders from parsed CSV/Excel row data.
     *
     * POST /int/v1/fleet-ops/orchestrator/import-orders
     */
    public function importOrders(Request $request): JsonResponse
    {
        $rows        = $request->input('rows', []);
        $companyUuid = session('company');

        if (empty($rows)) {
            return response()->json(['error' => 'No rows provided.'], 422);
        }

        $created = [];
        $failed  = [];

        foreach ($rows as $index => $row) {
            try {
                $orderData = [
                    'company_uuid'          => $companyUuid,
                    'status'                => 'created',
                    'type'                  => $row['type'] ?? 'default',
                    'notes'                 => $row['notes'] ?? null,
                    'scheduled_at'          => $row['scheduled_at'] ?? null,
                    'meta'                  => array_filter([
                        'weight_kg' => $row['weight_kg'] ?? null,
                        'volume_m3' => $row['volume_m3'] ?? null,
                        'parcels'   => $row['parcels'] ?? null,
                    ]),
                    'time_window_start'     => $row['time_window_start'] ?? null,
                    'time_window_end'       => $row['time_window_end'] ?? null,
                    'required_skills'       => $row['required_skills'] ?? [],
                    'orchestrator_priority' => $row['priority'] ?? 0,
                    '_pickup_address'       => $row['pickup_address'] ?? null,
                    '_dropoff_address'      => $row['dropoff_address'] ?? null,
                ];

                $order     = Order::create($orderData);
                $created[] = $order->public_id;
            } catch (\Exception $e) {
                $failed[] = ['row' => $index + 1, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'created' => $created,
            'failed'  => $failed,
        ]);
    }
}
