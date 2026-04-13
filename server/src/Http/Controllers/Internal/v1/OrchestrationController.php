<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Orchestrator\Order as OrchestratorOrderResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
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
 *   - Running orchestration phases (assign_vehicles, assign_drivers, optimize, optimize_routes, allocate)
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
        $companyUuid       = session('company');
        $mode              = $request->input('mode', 'assign_vehicles');
        $orderIds          = $request->input('order_ids', []);
        $vehicleIds        = $request->input('vehicle_ids', []);
        $driverIds         = $request->input('driver_ids', []);
        $options           = $request->input('options', []);
        // prior_assignments: assignments from previous phases that have not yet
        // been committed to the database. Keyed by order_id (public_id).
        $priorAssignments  = collect($request->input('prior_assignments', []))
            ->keyBy('order_id');

        // ── Resolve orders ────────────────────────────────────────────────────
        $ordersQuery = Order::where('company_uuid', $companyUuid)
            ->whereIn('status', ['created', 'dispatched', 'started'])
            ->with(['payload.dropoff', 'payload.pickup', 'payload.waypoints', 'payload.waypointMarkers', 'payload.entities']);

        if ($mode === 'assign_vehicles' || $mode === 'allocate') {
            // Exclude orders that already have a vehicle assigned in the DB
            // OR in a prior uncommitted phase.
            $priorVehicleAssignedOrderIds = $priorAssignments
                ->filter(fn ($a) => !empty($a['vehicle_id']))
                ->keys()
                ->toArray();
            $ordersQuery->whereNull('vehicle_assigned_uuid');
            if (!empty($priorVehicleAssignedOrderIds)) {
                $ordersQuery->whereNotIn('public_id', $priorVehicleAssignedOrderIds);
            }
        } elseif ($mode === 'optimize') {
            $ordersQuery->whereNotNull('vehicle_assigned_uuid');
        } elseif ($mode === 'optimize_routes') {
            // optimize_routes: re-sequence stops for selected orders.
            // No vehicle-assignment filter — the user picks the orders explicitly.
        } elseif ($mode === 'assign_drivers') {
            // For assign_drivers we need orders that have a vehicle assigned
            // (either committed to DB or from a prior phase) but no driver yet.
            $priorVehicleAssignedOrderIds = $priorAssignments
                ->filter(fn ($a) => !empty($a['vehicle_id']))
                ->keys()
                ->toArray();

            if (!empty($priorVehicleAssignedOrderIds)) {
                // Use the prior phase's vehicle assignments — fetch those orders
                // regardless of their DB vehicle_assigned_uuid.
                $ordersQuery->whereIn('public_id', $priorVehicleAssignedOrderIds)
                    ->whereNull('driver_assigned_uuid');
            } else {
                // Standalone assign_drivers (no prior vehicle phase):
                // Use all selected orders regardless of vehicle assignment.
                // The engine will assign both a vehicle and a driver together.
                $ordersQuery->whereNull('driver_assigned_uuid');
            }
        }

        if (!empty($orderIds)) {
            $ordersQuery->whereIn('public_id', $orderIds);
        }
        $orders = $ordersQuery->get();

        // Augment orders with prior-phase vehicle AND driver assignments so the
        // engines can group by vehicle_id and preserve driver_id even before the
        // plan is committed to the database.
        if ($priorAssignments->isNotEmpty()) {
            // Pre-load all drivers referenced in prior assignments so we can
            // attach them to vehicles without N+1 queries.
            $priorDriverIds = $priorAssignments
                ->pluck('driver_id')
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            $priorDriverMap = collect();
            if (!empty($priorDriverIds)) {
                $priorDriverMap = Driver::whereIn('public_id', $priorDriverIds)
                    ->get()
                    ->keyBy('public_id');
            }

            foreach ($orders as $order) {
                $prior = $priorAssignments->get($order->public_id);
                if (!$prior) {
                    continue;
                }

                // Temporarily set the vehicle_assigned_uuid on the model
                // so engines that group by this field work correctly.
                if (!empty($prior['vehicle_id']) && !$order->vehicle_assigned_uuid) {
                    // Resolve the Vehicle model and attach it
                    $vehicle = Vehicle::where('public_id', $prior['vehicle_id'])
                        ->with(['driver' => fn ($q) => $q->with(['scheduleItems'])])
                        ->first();
                    if ($vehicle) {
                        $order->vehicle_assigned_uuid = $vehicle->uuid;

                        // If the prior phase assigned a driver that is not yet
                        // linked to this vehicle in the DB, attach that driver
                        // to the vehicle relation so RouteSequencingEngine (and
                        // any other engine) can read $vehicle->driver correctly.
                        if (!empty($prior['driver_id'])) {
                            $priorDriver = $priorDriverMap->get($prior['driver_id']);
                            if ($priorDriver && (!$vehicle->driver || $vehicle->driver->public_id !== $prior['driver_id'])) {
                                $vehicle->setRelation('driver', $priorDriver);
                            }
                        }

                        $order->setRelation('vehicle', $vehicle);
                    }
                }

                // Also temporarily set driver_assigned_uuid so engines that
                // check this field (e.g. for deduplication) see the prior assignment.
                if (!empty($prior['driver_id']) && !$order->driver_assigned_uuid) {
                    $priorDriver = $priorDriverMap->get($prior['driver_id']);
                    if ($priorDriver) {
                        $order->driver_assigned_uuid = $priorDriver->uuid;
                        $order->setRelation('driverAssigned', $priorDriver);
                    }
                }
            }
        }

        // ── Resolve vehicles ──────────────────────────────────────────────────
        $vehiclesQuery = Vehicle::where('company_uuid', $companyUuid)
            ->with(['driver' => fn ($q) => $q->with(['scheduleItems'])]);

        if (!empty($vehicleIds)) {
            $vehiclesQuery->whereIn('public_id', $vehicleIds);
        } elseif (!empty($driverIds)) {
            $vehiclesQuery->whereHas('driver', fn ($q) => $q->whereIn('public_id', $driverIds));
        }

        // assign_vehicles, assign_drivers and optimize_routes do not require an
        // online/assigned driver — use all matching vehicles as-is.
        if (in_array($mode, ['assign_vehicles', 'assign_drivers', 'optimize_routes'])) {
            $vehicles = $vehiclesQuery->get();
        } else {
            // Legacy allocate / optimize modes require a driver to be linked.
            $vehicles = $vehiclesQuery->get()->filter(fn ($v) => $v->driver !== null);
        }

        if ($orders->isEmpty()) {
            return response()->json([
                'message'     => 'No orders found for the given criteria.',
                'assignments' => [],
                'unassigned'  => [],
            ], 200);
        }

        if ($vehicles->isEmpty() && $mode !== 'assign_drivers') {
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
            } elseif ($mode === 'optimize_routes') {
                // optimize_routes sequences stops within each vehicle's already-assigned
                // order group — it does NOT re-assign orders to different vehicles.
                //
                // IMPORTANT: Do NOT call $orders->load(['vehicle', 'vehicle.driver']) here.
                // The augmentation loop above already called setRelation('vehicle', $vehicle)
                // with the prior-phase driver attached via setRelation('driver', $priorDriver).
                // Calling ->load() would reload from the DB and OVERWRITE those in-memory
                // relations, losing the uncommitted driver assignment from a prior phase.
                //
                // Instead, only load the vehicle relation for orders that have a
                // vehicle_assigned_uuid in the DB but no in-memory relation set yet
                // (i.e. standalone optimize_routes without a prior assign_drivers phase).
                foreach ($orders as $order) {
                    if (!$order->relationLoaded('vehicle') && $order->vehicle_assigned_uuid) {
                        $vehicle = Vehicle::where('uuid', $order->vehicle_assigned_uuid)
                            ->with(['driver'])
                            ->first();
                        if ($vehicle) {
                            $order->setRelation('vehicle', $vehicle);
                        }
                    }
                }
                $engine = new RouteSequencingEngine();
                $result = $engine->sequence($orders, $options);
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
            // Only roll back if a transaction is still active.
            // A PDOException from a missing table can cause MySQL to implicitly
            // roll back the transaction before we reach this catch block.
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

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

        // ── Group multi-waypoint rows by order_ref ────────────────────────────
        // Rows with order_type = 'multi_waypoint' and the same order_ref are
        // collapsed into a single order where each row becomes one waypoint.
        $groups = [];
        foreach ($rows as $row) {
            $orderType = strtolower(trim($row['order_type'] ?? 'pickup_dropoff'));
            $orderRef  = trim($row['order_ref'] ?? '');

            if ($orderType === 'multi_waypoint' && $orderRef !== '') {
                $groups[$orderRef][] = $row;
            } else {
                // Each pickup/dropoff row is its own independent group.
                $groups['__single_' . Str::uuid()][] = $row;
            }
        }

        foreach ($groups as $groupKey => $groupRows) {
            DB::beginTransaction();
            try {
                // Use the first row for order-level metadata.
                $firstRow  = $groupRows[0];
                $orderType = strtolower(trim($firstRow['order_type'] ?? 'pickup_dropoff'));
                $isMulti   = $orderType === 'multi_waypoint';

                // ── Resolve OrderConfig ───────────────────────────────────────
                $orderConfigUuid = null;
                if (!empty($firstRow['type'])) {
                    $orderConfig = OrderConfig::resolveFromIdentifier($firstRow['type']);
                    if ($orderConfig) {
                        $orderConfigUuid = $orderConfig->uuid;
                    }
                }

                // ── Resolve Customer ─────────────────────────────────────────
                $customerUuid = null;
                $customerType = null;
                if (!empty($firstRow['customer_email']) || !empty($firstRow['customer_phone']) || !empty($firstRow['customer_name'])) {
                    $customerEntityType = strtolower(trim($firstRow['customer_type'] ?? 'contact'));
                    if ($customerEntityType === 'vendor') {
                        $vendor = $this->resolveOrCreateVendor($firstRow, $companyUuid, 'customer');
                        if ($vendor) {
                            $customerUuid = $vendor->uuid;
                            $customerType = 'Fleetbase\\FleetOps\\Models\\Vendor';
                        }
                    } else {
                        $contact = $this->resolveOrCreateContact($firstRow, $companyUuid, 'customer');
                        if ($contact) {
                            $customerUuid = $contact->uuid;
                            $customerType = 'Fleetbase\\FleetOps\\Models\\Contact';
                        }
                    }
                }

                // ── Resolve Facilitator ───────────────────────────────────────
                $facilitatorUuid = null;
                $facilitatorType = null;
                if (!empty($firstRow['facilitator_email']) || !empty($firstRow['facilitator_phone']) || !empty($firstRow['facilitator_name'])) {
                    $facilitatorEntityType = strtolower(trim($firstRow['facilitator_type'] ?? 'vendor'));
                    if ($facilitatorEntityType === 'contact') {
                        $contact = $this->resolveOrCreateContact($firstRow, $companyUuid, 'facilitator');
                        if ($contact) {
                            $facilitatorUuid = $contact->uuid;
                            $facilitatorType = 'Fleetbase\\FleetOps\\Models\\Contact';
                        }
                    } else {
                        $vendor = $this->resolveOrCreateVendor($firstRow, $companyUuid, 'facilitator');
                        if ($vendor) {
                            $facilitatorUuid = $vendor->uuid;
                            $facilitatorType = 'Fleetbase\\FleetOps\\Models\\Vendor';
                        }
                    }
                }

                // ── Resolve Vehicle ───────────────────────────────────────────
                $vehicleUuid = null;
                if (!empty($firstRow['vehicle_plate'])) {
                    $vehicle = Vehicle::where('company_uuid', $companyUuid)
                        ->where('plate_number', $firstRow['vehicle_plate'])
                        ->first();
                    if ($vehicle) {
                        $vehicleUuid = $vehicle->uuid;
                    }
                }

                // ── Resolve Driver ────────────────────────────────────────────
                $driverUuid       = null;
                $driverIdentifier = $firstRow['driver_email'] ?? $firstRow['driver_phone'] ?? $firstRow['driver_name'] ?? null;
                if ($driverIdentifier) {
                    $driver = Driver::findByIdentifier($driverIdentifier);
                    if ($driver) {
                        $driverUuid = $driver->uuid;
                    }
                }

                // ── Build required_skills array ───────────────────────────────
                $requiredSkills = [];
                if (!empty($firstRow['required_skills'])) {
                    $requiredSkills = array_filter(array_map('trim', explode(',', $firstRow['required_skills'])));
                }

                // ── Create the Order ─────────────────────────────────────────
                $order = Order::create([
                    'company_uuid'          => $companyUuid,
                    'order_config_uuid'     => $orderConfigUuid,
                    'customer_uuid'         => $customerUuid,
                    'customer_type'         => $customerType,
                    'facilitator_uuid'      => $facilitatorUuid,
                    'facilitator_type'      => $facilitatorType,
                    'vehicle_assigned_uuid' => $vehicleUuid,
                    'driver_assigned_uuid'  => $driverUuid,
                    'internal_id'           => $firstRow['internal_id'] ?? null,
                    'status'                => $firstRow['status'] ?? 'created',
                    'type'                  => $firstRow['type'] ?? 'default',
                    'notes'                 => $firstRow['notes'] ?? null,
                    'scheduled_at'          => !empty($firstRow['scheduled_at'])
                        ? Carbon::parse($firstRow['scheduled_at'])
                        : null,
                    'time_window_start'     => $firstRow['time_window_start'] ?? null,
                    'time_window_end'       => $firstRow['time_window_end'] ?? null,
                    'required_skills'       => $requiredSkills,
                    'orchestrator_priority' => (int) ($firstRow['priority'] ?? 0),
                    'meta'                  => array_filter([
                        'service_time_min' => $firstRow['service_time_min'] ?? null,
                        'order_type'       => $orderType,
                        'order_ref'        => $firstRow['order_ref'] ?? null,
                    ]),
                ]);

                // ── Create Payload ────────────────────────────────────────────
                $payload = Payload::create([
                    'company_uuid'       => $companyUuid,
                    'cod_amount'         => $firstRow['cod_amount'] ?? null,
                    'cod_currency'       => $firstRow['cod_currency'] ?? null,
                    'capacity_weight_kg' => $firstRow['weight_kg'] ?? null,
                    'capacity_volume_m3' => $firstRow['volume_m3'] ?? null,
                    'capacity_parcels'   => $firstRow['parcels'] ?? null,
                ]);

                $order->setPayload($payload);
                $order->save();

                // ── Attach Places ────────────────────────────────────────────────
                $entities = [];
                if ($isMulti) {
                    // ── Multi-waypoint ───────────────────────────────────────
                    // Each row with an address becomes a waypoint stop, tagged
                    // with a stable _import_id ('wp_0', 'wp_1', …).
                    //
                    // Multiple entities per order are supported: any row in the
                    // group that has entity fields will produce an entity.  If
                    // the row also has an address it is linked to that waypoint
                    // via _import_id; otherwise entity_destination is used to
                    // resolve the target stop (index, 'pickup', or 'dropoff').
                    $waypoints    = [];
                    $wpImportIds  = []; // rowIndex => _import_id for address rows

                    foreach ($groupRows as $wpIndex => $wpRow) {
                        $importId  = 'wp_' . $wpIndex;
                        $placeData = $this->buildPlaceData($wpRow, 'dropoff');
                        if (!empty($placeData['street1'])) {
                            $place = Place::createFromMixed($placeData, [], true);
                            if ($place) {
                                $waypoints[]             = [
                                    'place_uuid' => $place->uuid,
                                    'type'       => 'dropoff',
                                    '_import_id' => $importId,
                                ];
                                $wpImportIds[$wpIndex] = $importId;
                            }
                        }
                    }

                    if (!empty($waypoints)) {
                        $payload->setWaypoints($waypoints);
                    }

                    // Collect entities from ALL rows in the group
                    foreach ($groupRows as $wpIndex => $wpRow) {
                        $entityData = $this->buildEntityData($wpRow, $companyUuid);
                        if ($entityData === null) {
                            continue;
                        }

                        // Prefer the _import_id of this row's own waypoint;
                        // fall back to entity_destination if the row has no address.
                        if (isset($wpImportIds[$wpIndex])) {
                            $entityData['_import_id'] = $wpImportIds[$wpIndex];
                        } else {
                            $dest = $wpRow['entity_destination'] ?? null;
                            if ($dest !== null && $dest !== '') {
                                // Numeric string → waypoint index; otherwise 'pickup'/'dropoff'
                                if (is_numeric($dest)) {
                                    $targetIndex = (int) $dest;
                                    if (isset($wpImportIds[$targetIndex])) {
                                        $entityData['_import_id'] = $wpImportIds[$targetIndex];
                                    }
                                } else {
                                    $entityData['destination'] = $dest;
                                }
                            }
                        }

                        $entities[] = $entityData;
                    }
                } else {
                    // ── Pickup / Dropoff ─────────────────────────────────────
                    // Address fields come from the first row only.
                    // Entity rows: ALL rows in the group are scanned so that
                    // multiple entities (one per row) can be attached to the
                    // same order.  Only the first row is used for addresses.
                    $pickupData  = $this->buildPlaceData($firstRow, 'pickup');
                    $dropoffData = $this->buildPlaceData($firstRow, 'dropoff');

                    if (!empty($pickupData['street1'])) {
                        $payload->setPickup($pickupData, ['save' => true]);
                    }

                    if (!empty($dropoffData['street1'])) {
                        $payload->setDropoff($dropoffData, ['save' => true]);
                    }

                    // Collect entities from ALL rows in the group
                    foreach ($groupRows as $entityRow) {
                        $entityData = $this->buildEntityData($entityRow, $companyUuid);
                        if ($entityData === null) {
                            continue;
                        }
                        // Resolve destination from each row's entity_destination
                        // column; default to 'dropoff' when not set.
                        $dest                      = $entityRow['entity_destination'] ?? 'dropoff';
                        $entityData['destination'] = ($dest !== '') ? $dest : 'dropoff';
                        $entities[]                = $entityData;
                    }
                }

                // ── Attach Entities to Payload ──────────────────────────────
                if (!empty($entities)) {
                    $payload->load(['waypoints', 'pickup', 'dropoff']);
                    $payload->setEntities($entities);
                }

                DB::commit();
                $created[] = $order->public_id;
            } catch (\Exception $e) {
                DB::rollBack();
                $rowIndex = ($groupRows[0]['_rowIndex'] ?? '?');
                $failed[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'created' => $created,
            'failed'  => $failed,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a Place-compatible attributes array from a CSV row.
     *
     * @param array  $row    the mapped CSV row
     * @param string $prefix 'pickup' or 'dropoff'
     */
    private function buildPlaceData(array $row, string $prefix): array
    {
        return array_filter([
            'name'        => $row["{$prefix}_name"] ?? null,
            'street1'     => $row["{$prefix}_street1"] ?? null,
            'street2'     => $row["{$prefix}_street2"] ?? null,
            'city'        => $row["{$prefix}_city"] ?? null,
            'province'    => $row["{$prefix}_state"] ?? null,
            'postal_code' => $row["{$prefix}_postal_code"] ?? null,
            'country'     => $row["{$prefix}_country"] ?? null,
            'phone'       => $row["{$prefix}_phone"] ?? null,
            'location'    => $this->buildLocationPoint($row["{$prefix}_lat"] ?? null, $row["{$prefix}_lng"] ?? null),
        ]);
    }

    /**
     * Build a WKT POINT string from optional lat/lng strings.
     * Returns null when either value is missing or both are zero.
     */
    private function buildLocationPoint(?string $lat, ?string $lng): ?string
    {
        if (empty($lat) || empty($lng)) {
            return null;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF === 0.0 && $lngF === 0.0) {
            return null;
        }

        return "POINT({$lngF} {$latF})";
    }

    /**
     * Build an Entity-compatible attributes array from a CSV row.
     * Returns null when no entity fields are present in the row.
     *
     * @param array  $row         the mapped CSV row
     * @param string $companyUuid the current company UUID
     */
    private function buildEntityData(array $row, string $companyUuid): ?array
    {
        // Only build an entity if at least one entity field has a value
        $hasEntity = !empty($row['entity_name'])
            || !empty($row['entity_type'])
            || !empty($row['entity_sku'])
            || !empty($row['entity_barcode'])
            || !empty($row['entity_description']);

        if (!$hasEntity) {
            return null;
        }

        return array_filter([
            'company_uuid'     => $companyUuid,
            'name'             => $row['entity_name'] ?? null,
            'type'             => $row['entity_type'] ?? null,
            'description'      => $row['entity_description'] ?? null,
            'sku'              => $row['entity_sku'] ?? null,
            'barcode'          => $row['entity_barcode'] ?? null,
            'internal_id'      => $row['entity_internal_id'] ?? null,
            'declared_value'   => isset($row['entity_declared_value']) && $row['entity_declared_value'] !== ''
                                    ? (float) $row['entity_declared_value'] : null,
            'currency'         => $row['entity_currency'] ?? null,
            'price'            => isset($row['entity_price']) && $row['entity_price'] !== ''
                                    ? (float) $row['entity_price'] : null,
            'sale_price'       => isset($row['entity_sale_price']) && $row['entity_sale_price'] !== ''
                                    ? (float) $row['entity_sale_price'] : null,
            'weight'           => isset($row['entity_weight']) && $row['entity_weight'] !== ''
                                    ? (float) $row['entity_weight'] : null,
            'weight_unit'      => $row['entity_weight_unit'] ?? null,
            'length'           => isset($row['entity_length']) && $row['entity_length'] !== ''
                                    ? (float) $row['entity_length'] : null,
            'width'            => isset($row['entity_width']) && $row['entity_width'] !== ''
                                    ? (float) $row['entity_width'] : null,
            'height'           => isset($row['entity_height']) && $row['entity_height'] !== ''
                                    ? (float) $row['entity_height'] : null,
            'dimensions_unit'  => $row['entity_dimensions_unit'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Resolve an existing Contact by email/phone, or create a new one.
     *
     * @param array  $row         the mapped CSV row
     * @param string $companyUuid the company UUID
     * @param string $prefix      'customer' or 'facilitator'
     */
    private function resolveOrCreateContact(array $row, string $companyUuid, string $prefix): ?Contact
    {
        $email = $row["{$prefix}_email"] ?? null;
        $phone = $row["{$prefix}_phone"] ?? null;
        $name  = $row["{$prefix}_name"] ?? null;

        // Try to find by email first, then phone.
        $contact = null;
        if ($email) {
            $contact = Contact::where('company_uuid', $companyUuid)->where('email', $email)->first();
        }
        if (!$contact && $phone) {
            $contact = Contact::where('company_uuid', $companyUuid)->where('phone', $phone)->first();
        }

        if ($contact) {
            return $contact;
        }

        // Create a new contact if we have at least a name or email.
        if ($name || $email) {
            return Contact::create(array_filter([
                'company_uuid' => $companyUuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'type'         => 'customer',
            ]));
        }

        return null;
    }

    /**
     * Resolve an existing Vendor by email/phone, or create a new one.
     *
     * @param array  $row         the mapped CSV row
     * @param string $companyUuid the company UUID
     * @param string $prefix      'customer' or 'facilitator'
     */
    private function resolveOrCreateVendor(array $row, string $companyUuid, string $prefix): ?Vendor
    {
        $email = $row["{$prefix}_email"] ?? null;
        $phone = $row["{$prefix}_phone"] ?? null;
        $name  = $row["{$prefix}_name"] ?? null;

        $vendor = null;
        if ($email) {
            $vendor = Vendor::where('company_uuid', $companyUuid)->where('email', $email)->first();
        }
        if (!$vendor && $phone) {
            $vendor = Vendor::where('company_uuid', $companyUuid)->where('phone', $phone)->first();
        }
        if (!$vendor && $name) {
            $vendor = Vendor::where('company_uuid', $companyUuid)->whereRaw('lower(name) = ?', [strtolower($name)])->first();
        }

        if ($vendor) {
            return $vendor;
        }

        if ($name || $email) {
            return Vendor::create(array_filter([
                'company_uuid' => $companyUuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
            ]));
        }

        return null;
    }
}
