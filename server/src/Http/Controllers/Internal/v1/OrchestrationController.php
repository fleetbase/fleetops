<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Orchestrator\Order as OrchestratorOrderResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\OrchestrationRun;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings;
use Fleetbase\FleetOps\Orchestration\Support\RouteMetrics;
use Fleetbase\FleetOps\Orchestration\Support\UnassignedReasons;
use Fleetbase\FleetOps\Support\Dispatch\DispatchDate;
use Fleetbase\FleetOps\Support\Dispatch\ManifestPublisher;
use Fleetbase\FleetOps\Support\Dispatch\RouteReassignment;
use Fleetbase\FleetOps\Support\Routing\RoutingProviderPolicy;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
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
    use AuthorizesMethods;

    /**
     * Engines that assign orders to vehicles without producing a stop sequence.
     */
    protected const ASSIGNMENT_ONLY_ENGINES = ['greedy', 'capacity'];

    /**
     * Order statuses a plan run may take in. `order_statuses` narrows this.
     */
    public const PLANNABLE_STATUSES = ['created', 'dispatched', 'started'];

    public function __construct(protected OrchestrationEngineRegistry $registry)
    {
        $this->authorizeMethods([
            'orders'                => 'list order',
            'run'                   => 'optimize order',
            'preview'               => 'list order',
            'commit'                => 'assign-driver-for order',
            'engines'               => 'list order',
            'orderConfigFields'     => 'list order',
            'importOrders'          => 'import order',
        ]);
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
        $companyUuid = $this->companyUuid();

        $query = $this->orchestratorOrdersQuery($companyUuid);

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
     * Scope: the orders in `order_ids`, or, without them, the orders whose
     * `scheduled_at` falls on `scheduled_date` (Y-m-d). One of the two is
     * required; a run never plans every unassigned order on every date.
     * `order_statuses` narrows which statuses are taken in (any of created,
     * dispatched, started; all three when omitted).
     *
     * The response carries `unassigned_reasons` keyed by order public id and
     * `plan_run_id`, the stored run those reasons were saved with.
     *
     * POST /int/v1/fleet-ops/orchestrator/run
     */
    public function run(Request $request): JsonResponse
    {
        $companyUuid       = $this->companyUuid();
        $mode              = $request->input('mode', 'assign_vehicles');
        $orderIds          = array_values(array_filter((array) $request->input('order_ids', []), fn ($id) => is_string($id) && $id !== ''));
        $scheduledDate     = null;
        if ($request->filled('scheduled_date')) {
            $scheduledDate = DispatchDate::normalize($request->input('scheduled_date'));
            if (!$scheduledDate) {
                return response()->json(['error' => 'scheduled_date must be a date (Y-m-d).'], 422);
            }
        }
        if (empty($orderIds) && !$scheduledDate) {
            return response()->json(['error' => 'Send order_ids or a scheduled_date to plan.'], 422);
        }
        $statuses = $this->plannableStatuses($request->input('order_statuses'));
        if ($statuses === []) {
            return response()->json(['error' => 'order_statuses may only contain: ' . implode(', ', self::PLANNABLE_STATUSES) . '.'], 422);
        }
        $vehicleIds        = $request->input('vehicle_ids', []);
        $driverIds         = $request->input('driver_ids', []);
        $options           = $request->input('options', []);
        // prior_assignments: assignments from previous phases that have not yet
        // been committed to the database. Keyed by order_id (public_id).
        $priorAssignments  = collect($request->input('prior_assignments', []))
            ->keyBy('order_id');

        // ── Resolve orders ────────────────────────────────────────────────────
        $ordersQuery = $this->orchestrationRunOrdersQuery($companyUuid);

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
        } else {
            $ordersQuery->whereDate('scheduled_at', $scheduledDate);
        }
        if ($statuses !== null) {
            $ordersQuery->whereIn('status', $statuses);
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
                $priorDriverMap = $this->driversByPublicId($priorDriverIds);
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
                    $vehicle = $this->vehicleByPublicIdWithDriver($prior['vehicle_id']);
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
        $vehiclesQuery = $this->orchestrationRunVehiclesQuery($companyUuid);

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
                'message'            => 'No orders found for the given criteria.',
                'assignments'        => [],
                'unassigned'         => [],
                'unassigned_reasons' => (object) [],
                'plan_run_id'        => null,
                'scheduled_date'     => $scheduledDate,
            ], 200);
        }

        if ($vehicles->isEmpty() && $mode !== 'assign_drivers') {
            $unassigned = $orders->pluck('public_id')->values()->all();

            return response()->json($this->withPlanRun([
                'message'            => 'No available vehicles found.',
                'assignments'        => [],
                'unassigned'         => $unassigned,
                'unassigned_reasons' => UnassignedReasons::all($unassigned, 'no_available_vehicle'),
            ], $mode, null, $scheduledDate, $orders), 200);
        }

        // ── Run engine ────────────────────────────────────────────────────────
        $engineId = $mode === 'assign_drivers'
            ? 'driver_assignment'
            : ($request->input('options.engine') ?: $this->orchestratorEngineSetting());

        try {
            if ($mode === 'assign_drivers') {
                $engine = $this->driverAssignmentEngine();
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
                        $vehicle = $this->vehicleByUuidWithDriver($order->vehicle_assigned_uuid);
                        if ($vehicle) {
                            $order->setRelation('vehicle', $vehicle);
                        }
                    }
                }
                $result = $this->optimizeRoutes($engineId, $orders, $options);
            } else {
                $result = $this->registry->allocateWithFallback($engineId, $orders, $vehicles, $options);
            }
        } catch (\RuntimeException $e) {
            // Engine is unavailable (e.g. VROOM not reachable) and there is no
            // built-in engine to fall back to.
            // Return a structured JSON 503 so the frontend can display a
            // user-friendly message instead of an unhandled exception page.
            return response()->json([
                'error'  => $e->getMessage(),
                'hint'   => 'If you are using the VROOM engine, ensure the VROOM service is running and VROOM_HOST is configured correctly. Alternatively, switch to the built-in "greedy" engine in Orchestrator Settings.',
                'engine' => $engineId,
            ], 503);
        }

        if ($mode !== 'assign_drivers') {
            $result = RouteMetrics::annotate($result, $orders, $vehicles);
        }

        $ranEngine                    = $result['summary']['engine'] ?? $engineId;
        $result['unassigned_reasons'] = UnassignedReasons::fromResult($result, $ranEngine);

        return response()->json($this->withPlanRun($result, $mode, $ranEngine, $scheduledDate, $orders));
    }

    /**
     * The statuses `order_statuses` asks for, limited to plannable ones. Null
     * when it was not sent (no narrowing); an empty array when it named none
     * of the plannable statuses.
     */
    protected function plannableStatuses(mixed $requested): ?array
    {
        if ($requested === null || $requested === [] || $requested === '') {
            return null;
        }

        return array_values(array_intersect(self::PLANNABLE_STATUSES, (array) $requested));
    }

    /**
     * Store the run with its reasons and add `plan_run_id` and
     * `scheduled_date` to the response. An empty reasons map is sent as `{}`
     * so clients always read an object keyed by order id.
     */
    protected function withPlanRun(array $result, string $mode, ?string $engine, ?string $scheduledDate, $orders): array
    {
        $reasons = $result['unassigned_reasons'] ?? [];
        $run     = $this->recordPlanRun([
            'company_uuid'       => $this->companyUuid(),
            'created_by_uuid'    => session('user'),
            'mode'               => $mode,
            'engine'             => $engine,
            'scheduled_date'     => $scheduledDate,
            'order_ids'          => $orders->pluck('public_id')->values()->all(),
            'assignments'        => $result['assignments'] ?? [],
            'unassigned'         => array_values((array) ($result['unassigned'] ?? [])),
            'unassigned_reasons' => $reasons,
            'summary'            => $result['summary'] ?? [],
        ]);

        $result['unassigned_reasons'] = $reasons ?: (object) [];
        $result['plan_run_id']        = $run?->public_id;
        $result['scheduled_date']     = $scheduledDate;

        return $result;
    }

    protected function recordPlanRun(array $attributes): ?OrchestrationRun
    {
        return OrchestrationRun::create($attributes);
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

    protected function companyUuid(): ?string
    {
        return session('company');
    }

    protected function orchestratorOrdersQuery(?string $companyUuid): mixed
    {
        return Order::where('company_uuid', $companyUuid)->whereIn('status', ['created', 'dispatched', 'started']);
    }

    protected function orchestrationRunOrdersQuery(?string $companyUuid): mixed
    {
        return Order::where('company_uuid', $companyUuid)
            ->whereIn('status', ['created', 'dispatched', 'started'])
            ->with(['payload.dropoff', 'payload.pickup', 'payload.waypoints', 'payload.waypointMarkers', 'payload.entities']);
    }

    protected function orchestrationRunVehiclesQuery(?string $companyUuid): mixed
    {
        return Vehicle::where('company_uuid', $companyUuid)
            ->with(['driver' => fn ($q) => $q->with(['scheduleItems'])]);
    }

    protected function driversByPublicId(array $publicIds)
    {
        return Driver::whereIn('public_id', $publicIds)
            ->get()
            ->keyBy('public_id');
    }

    protected function vehicleByPublicIdWithDriver(string $publicId): ?Vehicle
    {
        return Vehicle::where('public_id', $publicId)
            ->with(['driver' => fn ($q) => $q->with(['scheduleItems'])])
            ->first();
    }

    protected function vehicleByUuidWithDriver(string $uuid): ?Vehicle
    {
        return Vehicle::where('uuid', $uuid)
            ->with(['driver'])
            ->first();
    }

    protected function orchestratorEngineSetting(): string
    {
        return OrchestratorSettings::engineForCompany($this->companyUuid());
    }

    /**
     * Re-sequence each vehicle's already-assigned orders.
     *
     * A solver engine (e.g. VROOM) is run once per vehicle, restricted to
     * that vehicle and its orders, so the stop order, arrival times and
     * distances come from the selected engine. Engines that only assign
     * orders (greedy, capacity) cannot sequence stops, and a solver that is
     * missing or unavailable is replaced; in both cases the built-in route
     * sequencer runs and the result says which engine ran and why.
     */
    protected function optimizeRoutes(string $engineId, $orders, array $options): array
    {
        $warn = true;
        if (in_array($engineId, self::ASSIGNMENT_ONLY_ENGINES, true)) {
            $warn   = false;
            $reason = sprintf('The "%s" engine assigns orders but does not sequence stops; stops were sequenced by the built-in route sequencer.', $engineId);
        } elseif (!$this->registry->has($engineId)) {
            $reason = sprintf('No orchestration engine is registered as "%s".', $engineId);
        } else {
            try {
                return $this->sequenceWithEngine($this->registry->resolve($engineId), $orders, $options);
            } catch (\RuntimeException $e) {
                $reason = $e->getMessage();
            }
        }

        $result = $this->routeSequencingEngine()->sequence($orders, $options);

        return OrchestrationEngineRegistry::markFallback($result, $result['summary']['engine'] ?? 'route_sequencing', $engineId, $reason, $warn);
    }

    /**
     * Run a solver engine once per vehicle group, keeping every order on its vehicle.
     */
    protected function sequenceWithEngine(OrchestrationEngineInterface $engine, $orders, array $options): array
    {
        $options     = array_merge($options, ['allocation_strategy' => 'route_aware']);
        $assignments = [];
        $unassigned  = [];

        foreach ($orders->groupBy(fn ($order) => $order->vehicle_assigned_uuid ?? '') as $vehicleUuid => $vehicleOrders) {
            $vehicle = $vehicleUuid !== '' && $vehicleOrders->first()->relationLoaded('vehicle') ? $vehicleOrders->first()->vehicle : null;
            if (!$vehicle) {
                // Without an assigned vehicle there is no route to sequence.
                array_push($unassigned, ...$vehicleOrders->pluck('public_id')->all());
                continue;
            }

            $result = $engine->allocate($vehicleOrders->values(), collect([$vehicle]), $options);
            array_push($assignments, ...($result['assignments'] ?? []));
            array_push($unassigned, ...($result['unassigned'] ?? []));
        }

        return [
            'assignments' => $assignments,
            'unassigned'  => array_values(array_unique($unassigned)),
            'summary'     => [
                'engine'     => $engine->getIdentifier(),
                'assigned'   => count($assignments),
                'unassigned' => count(array_unique($unassigned)),
            ],
        ];
    }

    protected function driverAssignmentEngine(): DriverAssignmentEngine
    {
        return new DriverAssignmentEngine();
    }

    protected function routeSequencingEngine(): RouteSequencingEngine
    {
        return new RouteSequencingEngine();
    }

    /**
     * Commit an orchestration plan — creates Manifests and ManifestStops.
     *
     * Body:
     *   assignments     the plan, as returned by run (with any overrides)
     *   scheduled_date  Y-m-d the routes are for (today when omitted). The
     *                   manifests are dated by it and each order's
     *                   `scheduled_at` moves to that day, keeping its time
     *                   of day (or taking the stop's planned arrival).
     *   plan_run_id     the run the plan came from; marked committed
     *   publish         true to publish the new routes to their drivers now
     *   publish_at      ISO 8601: publish them at that time instead
     *
     * Manifests are created as drafts, and drivers hear nothing until they
     * are published. An order already on another open route leaves it first
     * (that route closes up), so a re-plan never leaves an order on two routes.
     *
     * Does NOT trigger dispatch or update order status. That is the
     * responsibility of the operational flow (driver actions / dispatcher).
     *
     * POST /int/v1/fleet-ops/orchestrator/commit
     */
    public function commit(Request $request): JsonResponse
    {
        $assignments   = $request->input('assignments', []);
        $companyUuid   = session('company');

        if (empty($assignments)) {
            return response()->json(['error' => 'No assignments provided.'], 422);
        }

        $scheduledDate = now()->toDateString();
        if ($request->filled('scheduled_date')) {
            $scheduledDate = DispatchDate::normalize($request->input('scheduled_date'));
            if (!$scheduledDate) {
                return response()->json(['error' => 'scheduled_date must be a date (Y-m-d).'], 422);
            }
        }

        $publishAt = null;
        if ($request->filled('publish_at')) {
            $publishAt = DispatchDate::moment($request->input('publish_at'));
            if (!$publishAt) {
                return response()->json(['error' => 'publish_at must be a date and time.'], 422);
            }
        }
        $publishNow = !$publishAt && $request->boolean('publish');

        $committed       = [];
        $failed          = [];
        $manifests       = [];
        $manifestRecords = [];
        $resequenced     = false;

        $this->beginOrchestrationTransaction();
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
                $vehicle = $this->findVehicleByPublicId($vehiclePublicId);
                if (!$vehicle) {
                    foreach ($vehicleAssignments as $a) {
                        $failed[] = $a['order_id'];
                    }
                    continue;
                }

                // Driver is optional (vehicle-only assignment)
                $driverPublicId = $vehicleAssignments[0]['driver_id'] ?? null;
                $driver         = $driverPublicId
                    ? $this->findDriverByPublicId($driverPublicId)
                    : null;

                $totalDistance = (int) array_sum(array_column($vehicleAssignments, 'distance'));
                $totalDuration = (int) array_sum(array_column($vehicleAssignments, 'duration'));

                // Create Manifest
                $manifest = $this->createManifest([
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
                    $order = $this->findOrderByPublicId($assignment['order_id']);
                    if (!$order) {
                        $failed[] = $assignment['order_id'];
                        continue;
                    }

                    // A re-plan moves the order off the route it was on.
                    $this->releaseFromPreviousManifest($order, $manifest);

                    $placeUuid = $order->payload?->dropoff?->uuid ?? null;

                    $this->createManifestStop([
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
                    $order->scheduled_at = $this->scheduledAtOn($order, $scheduledDate, $assignment['arrival'] ?? null);

                    // A draft is the dispatcher's alone: the driver hears about
                    // these orders when the route is published.
                    $order->silentDriverAssignment = true;
                    try {
                        $order->save();
                    } finally {
                        $order->silentDriverAssignment = false;
                    }

                    // Update waypoint sequence if provided
                    if (!empty($assignment['waypoint_sequence']) && $order->payload) {
                        foreach ($assignment['waypoint_sequence'] as $seq => $waypointId) {
                            $this->updateWaypointSequence($order->payload_uuid, $waypointId, $seq);
                            $resequenced = true;
                        }
                    }

                    $committed[] = $assignment['order_id'];
                }

                $manifests[]       = $manifest->public_id;
                $manifestRecords[] = $manifest;
            }

            $this->commitOrchestrationTransaction();
        } catch (\Exception $e) {
            // Only roll back if a transaction is still active.
            // A PDOException from a missing table can cause MySQL to implicitly
            // roll back the transaction before we reach this catch block.
            if ($this->orchestrationTransactionLevel() > 0) {
                $this->rollBackOrchestrationTransaction();
            }

            return response()->json(['error' => 'Commit failed: ' . $e->getMessage()], 500);
        }

        // Waypoint sequences are written without model events
        if ($resequenced) {
            Waypoint::invalidateApiCacheManually($companyUuid);
        }

        $this->markPlanRunCommitted($request->input('plan_run_id'), $companyUuid);

        $published = [];
        $scheduled = [];
        if ($publishNow || $publishAt) {
            $publisher = $this->manifestPublisher();
            foreach ($manifestRecords as $manifest) {
                if (!($publishAt ? $publisher->schedule($manifest, $publishAt) : $publisher->publish($manifest))) {
                    continue;
                }
                // A booked time that has already passed publishes at once.
                if ($manifest->isDraft()) {
                    $scheduled[] = $manifest->public_id;
                } else {
                    $published[] = $manifest->public_id;
                }
            }
        }

        return response()->json([
            'committed'      => $committed,
            'failed'         => $failed,
            'manifests'      => $manifests,
            'scheduled_date' => $scheduledDate,
            'published'      => $published,
            'scheduled'      => $scheduled,
        ]);
    }

    /**
     * The order's `scheduled_at` moved to the plan's day. Its time of day is
     * kept; an order with none takes the stop's planned arrival when that is
     * on the same day, else the start of the day.
     */
    protected function scheduledAtOn(Order $order, string $date, mixed $arrival): Carbon
    {
        $current = $order->scheduled_at ? Carbon::parse($order->scheduled_at) : null;
        if ($current) {
            return Carbon::parse($date . ' ' . $current->format('H:i:s'), $current->getTimezone());
        }

        if (is_numeric($arrival)) {
            $planned = Carbon::createFromTimestamp((int) $arrival);
            if ($planned->toDateString() === $date) {
                return $planned;
            }
        }

        return Carbon::parse($date)->startOfDay();
    }

    protected function releaseFromPreviousManifest(Order $order, Manifest $manifest): void
    {
        $this->routeReassignment()->release($order, $manifest);
    }

    protected function markPlanRunCommitted(mixed $planRunId, ?string $companyUuid): void
    {
        if (!is_string($planRunId) || $planRunId === '') {
            return;
        }

        OrchestrationRun::where('public_id', $planRunId)
            ->where('company_uuid', $companyUuid)
            ->whereNull('committed_at')
            ->update(['committed_at' => now()]);
    }

    protected function routeReassignment(): RouteReassignment
    {
        return app(RouteReassignment::class);
    }

    protected function manifestPublisher(): ManifestPublisher
    {
        return app(ManifestPublisher::class);
    }

    protected function beginOrchestrationTransaction(): void
    {
        DB::beginTransaction();
    }

    protected function commitOrchestrationTransaction(): void
    {
        DB::commit();
    }

    protected function rollBackOrchestrationTransaction(): void
    {
        DB::rollBack();
    }

    protected function orchestrationTransactionLevel(): int
    {
        return DB::transactionLevel();
    }

    protected function findVehicleByPublicId(string $publicId): ?Vehicle
    {
        return Vehicle::where('public_id', $publicId)->first();
    }

    protected function findDriverByPublicId(string $publicId): ?Driver
    {
        return Driver::where('public_id', $publicId)->first();
    }

    protected function findOrderByPublicId(string $publicId): ?Order
    {
        return Order::where('public_id', $publicId)->first();
    }

    protected function createManifest(array $attributes): Manifest
    {
        return Manifest::create($attributes);
    }

    protected function createManifestStop(array $attributes): ManifestStop
    {
        return ManifestStop::create($attributes);
    }

    protected function updateWaypointSequence(string $payloadUuid, string $waypointPublicId, int|string $sequence): void
    {
        DB::table('waypoints')
            ->where('payload_uuid', $payloadUuid)
            ->where('public_id', $waypointPublicId)
            ->update(['order' => $sequence]);
    }

    /**
     * Return available orchestration engines.
     *
     * GET /int/v1/fleet-ops/orchestrator/engines
     */
    public function engines(): JsonResponse
    {
        // Engines whose provider the administrator switched off are not offered
        $companyUuid = $this->companyUuid();

        return response()->json([
            'engines' => array_values(array_filter($this->registry->available(), fn ($engine) => RoutingProviderPolicy::allows($engine['id'], $companyUuid))),
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

        $configs = $this->getOrderConfigFieldConfigs($companyUuid)
            ->map(function ($config) {
                // customFields is a morphMany on subject_uuid/subject_type.
                // If the eager load returned nothing (e.g. subject_type mismatch),
                // fall back to a direct query by subject_uuid.
                $customFields = $config->customFields;
                if ($customFields->isEmpty()) {
                    $customFields = $this->getCustomFieldsForOrderConfig($config->uuid);
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

    protected function getOrderConfigFieldConfigs(string $companyUuid)
    {
        return OrderConfig::where('company_uuid', $companyUuid)
            ->with('customFields')
            ->get(['uuid', 'public_id', 'name', 'key']);
    }

    protected function getCustomFieldsForOrderConfig(string $orderConfigUuid)
    {
        return \Fleetbase\Models\CustomField::where('subject_uuid', $orderConfigUuid)
            ->orderBy('order')
            ->get();
    }

    /**
     * Import orders from parsed CSV/Excel row data.
     *
     * Body:
     *   rows             mapped rows; `_rowIndex` names a row in the outcomes
     *                    (its position in `rows` when absent)
     *   validate_only    true to check the rows and create nothing, e.g. after
     *                    the dispatcher edited the rows that failed
     *   import_batch_id  join an earlier import's batch (re-submitting fixed
     *                    rows); a new batch id is generated when absent
     *
     * Every row gets an outcome, in the order sent:
     *   { row, status: created | failed | valid, order_id?, reason?, errors?: { field: message } }
     * Rows of one multi-waypoint order (same `order_ref`) share an outcome: one
     * bad row fails the whole order. Created orders carry
     * `meta.imported_via_orchestrator = true` and `meta.import_batch_id`.
     *
     * `created` (order ids) and `failed` ([{ row, error }]) are kept for
     * clients written against the earlier response.
     *
     * POST /int/v1/fleet-ops/orchestrator/import-orders
     */
    public function importOrders(Request $request): JsonResponse
    {
        $rows        = $request->input('rows', []);
        $companyUuid = session('company');

        if (empty($rows) || !is_array($rows)) {
            return response()->json(['error' => 'No rows provided.'], 422);
        }

        $validateOnly = $request->boolean('validate_only');
        $batchId      = $request->input('import_batch_id');
        if (!is_string($batchId) || trim($batchId) === '') {
            $batchId = (string) Str::uuid();
        }

        $created  = [];
        $failed   = [];
        $outcomes = [];

        // ── Group multi-waypoint rows by order_ref ────────────────────────────
        // Rows with order_type = 'multi_waypoint' and the same order_ref are
        // collapsed into a single order where each row becomes one waypoint.
        $groups = [];
        foreach (array_values($rows) as $position => $row) {
            $row                 = is_array($row) ? $row : [];
            $row['_rowIndex']    = $row['_rowIndex'] ?? $position;
            $orderType           = strtolower(trim((string) ($row['order_type'] ?? 'pickup_dropoff')));
            $orderRef            = trim((string) ($row['order_ref'] ?? ''));
            $outcomes[$position] = ['row' => $row['_rowIndex']];

            if ($orderType === 'multi_waypoint' && $orderRef !== '') {
                $groups['__multi_' . $orderRef][$position] = $row;
            } else {
                // Each pickup/dropoff row is its own independent group.
                $groups['__single_' . $position][$position] = $row;
            }
        }

        foreach ($groups as $groupRows) {
            $errors = $this->importGroupErrors($groupRows);
            if ($errors) {
                $reason = 'Another row of this order is invalid.';
                foreach ($groupRows as $position => $row) {
                    $rowErrors           = $errors[$position] ?? [];
                    $outcomes[$position] += ['status' => 'failed', 'reason' => $rowErrors ? reset($rowErrors) : $reason, 'errors' => (object) $rowErrors];
                }
                $firstErrors = $errors[array_key_first($errors)];
                $failed[]    = ['row' => $groupRows[array_key_first($groupRows)]['_rowIndex'], 'error' => reset($firstErrors)];
                continue;
            }

            if ($validateOnly) {
                foreach (array_keys($groupRows) as $position) {
                    $outcomes[$position] += ['status' => 'valid'];
                }
                continue;
            }

            $positions = array_keys($groupRows);
            $groupRows = array_values($groupRows);

            DB::beginTransaction();
            try {
                // Use the first row for order-level metadata.
                $firstRow  = $groupRows[0];
                $orderType = strtolower(trim($firstRow['order_type'] ?? 'pickup_dropoff'));
                $isMulti   = $orderType === 'multi_waypoint';

                // ── Resolve OrderConfig ───────────────────────────────────────
                $orderConfigUuid     = null;
                $resolvedOrderConfig = null;
                if (!empty($firstRow['type'])) {
                    $resolvedOrderConfig = OrderConfig::resolveFromIdentifier($firstRow['type']);
                    if ($resolvedOrderConfig) {
                        $orderConfigUuid = $resolvedOrderConfig->uuid;
                    }
                }
                $resolvedOrderConfig ??= OrderConfig::defaultOrCreate();
                $orderConfigUuid = $resolvedOrderConfig?->uuid;

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
                    'type'                  => $resolvedOrderConfig?->key ?? 'transport',
                    'notes'                 => $firstRow['notes'] ?? null,
                    'scheduled_at'          => !empty($firstRow['scheduled_at'])
                        ? Carbon::parse($firstRow['scheduled_at'])
                        : null,
                    'time_window_start'     => $firstRow['time_window_start'] ?? null,
                    'time_window_end'       => $firstRow['time_window_end'] ?? null,
                    'required_skills'       => $requiredSkills,
                    'orchestrator_priority' => (int) ($firstRow['priority'] ?? 0),
                    'meta'                  => array_filter([
                        'service_time_min'          => $firstRow['service_time_min'] ?? null,
                        'order_type'                => $orderType,
                        'order_ref'                 => $firstRow['order_ref'] ?? null,
                        'imported_via_orchestrator' => true,
                        'import_batch_id'           => $batchId,
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
                        if ($this->importHasPlace($wpRow, 'dropoff')) {
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

                    if ($this->importHasPlace($firstRow, 'pickup')) {
                        $payload->setPickup($pickupData, ['save' => true]);
                    }

                    if ($this->importHasPlace($firstRow, 'dropoff')) {
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
                foreach ($positions as $position) {
                    $outcomes[$position] += ['status' => 'created', 'order_id' => $order->public_id];
                }
            } catch (\Exception $e) {
                DB::rollBack();
                $failed[] = ['row' => $groupRows[0]['_rowIndex'], 'error' => $e->getMessage()];
                foreach ($positions as $position) {
                    $outcomes[$position] += ['status' => 'failed', 'reason' => $e->getMessage(), 'errors' => (object) []];
                }
            }
        }

        ksort($outcomes);
        $outcomes = array_values($outcomes);
        $counts   = array_count_values(array_column($outcomes, 'status'));

        return response()->json([
            'import_batch_id' => $batchId,
            'validate_only'   => $validateOnly,
            'rows'            => $outcomes,
            'summary'         => [
                'created' => $counts['created'] ?? 0,
                'failed'  => $counts['failed'] ?? 0,
                'valid'   => $counts['valid'] ?? 0,
            ],
            'created'         => $created,
            'failed'          => $failed,
        ]);
    }

    /**
     * Field problems in one order's rows, keyed by row position then field.
     * Checks what would otherwise fail inside the import or be silently
     * dropped: a missing drop-off, unreadable dates and numbers, coordinates
     * off the globe and unknown types.
     *
     * @return array<int, array<string, string>>
     */
    protected function importGroupErrors(array $groupRows): array
    {
        $errors = [];
        $first  = reset($groupRows);
        $multi  = strtolower(trim((string) ($first['order_type'] ?? 'pickup_dropoff'))) === 'multi_waypoint';

        foreach ($groupRows as $position => $row) {
            $rowErrors = [];

            $orderType = strtolower(trim((string) ($row['order_type'] ?? '')));
            if ($orderType !== '' && !in_array($orderType, ['pickup_dropoff', 'multi_waypoint'], true)) {
                $rowErrors['order_type'] = 'order_type must be pickup_dropoff or multi_waypoint.';
            }

            if (!$multi && !$this->importHasPlace($row, 'dropoff')) {
                $rowErrors['dropoff_street1'] = 'A drop-off street address or coordinates are required.';
            }

            foreach (['scheduled_at', 'time_window_start', 'time_window_end'] as $field) {
                if (!$this->importBlank($row[$field] ?? null) && !DispatchDate::moment((string) $row[$field])) {
                    $rowErrors[$field] = sprintf('%s is not a date or time.', $field);
                }
            }

            foreach (['priority', 'parcels'] as $field) {
                if (!$this->importBlank($row[$field] ?? null) && filter_var($row[$field], FILTER_VALIDATE_INT) === false) {
                    $rowErrors[$field] = sprintf('%s must be a whole number.', $field);
                }
            }

            foreach (['weight_kg', 'volume_m3', 'cod_amount', 'service_time_min', 'entity_declared_value', 'entity_price', 'entity_sale_price', 'entity_weight', 'entity_length', 'entity_width', 'entity_height'] as $field) {
                if (!$this->importBlank($row[$field] ?? null) && !is_numeric($row[$field])) {
                    $rowErrors[$field] = sprintf('%s must be a number.', $field);
                }
            }

            foreach (['pickup', 'dropoff'] as $prefix) {
                foreach (["{$prefix}_lat" => 90, "{$prefix}_lng" => 180] as $field => $limit) {
                    $value = $row[$field] ?? null;
                    if (!$this->importBlank($value) && (!is_numeric($value) || abs((float) $value) > $limit)) {
                        $rowErrors[$field] = sprintf('%s must be a number between -%d and %d.', $field, $limit, $limit);
                    }
                }
            }

            foreach (['customer_type' => ['contact', 'vendor'], 'facilitator_type' => ['contact', 'vendor']] as $field => $allowed) {
                $value = strtolower(trim((string) ($row[$field] ?? '')));
                if ($value !== '' && !in_array($value, $allowed, true)) {
                    $rowErrors[$field] = sprintf('%s must be contact or vendor.', $field);
                }
            }

            if ($rowErrors) {
                $errors[$position] = $rowErrors;
            }
        }

        if ($multi && !array_filter($groupRows, fn ($row) => $this->importHasPlace($row, 'dropoff'))) {
            $position                             = array_key_first($groupRows);
            $errors[$position]['dropoff_street1'] = 'A multi-waypoint order needs at least one row with a street address or coordinates.';
        }

        return $errors;
    }

    /**
     * Whether a row names a place for `pickup` or `dropoff`: a street, or
     * coordinates (which buildLocationPoint turns into the place's location).
     */
    protected function importHasPlace(array $row, string $prefix): bool
    {
        if (trim((string) ($row["{$prefix}_street1"] ?? '')) !== '') {
            return true;
        }

        $lat = $row["{$prefix}_lat"] ?? null;
        $lng = $row["{$prefix}_lng"] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return false;
        }

        return $this->buildLocationPoint((string) $lat, (string) $lng) !== null;
    }

    protected function importBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
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
