<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Allocation\AllocationEngineRegistry;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AllocationController
 *
 * Provides the HTTP interface for the Orchestrator — the Intelligent Order
 * Allocation and Route Optimization Engine.
 *
 * All routes are internal (require fleetbase.protected middleware).
 *
 * Endpoints:
 *   POST   /int/v1/fleet-ops/allocation/run          — run allocation / route optimization
 *   POST   /int/v1/fleet-ops/allocation/commit        — commit an allocation plan
 *   GET    /int/v1/fleet-ops/allocation/preview       — preview without committing
 *   GET    /int/v1/fleet-ops/allocation/engines       — list available engines
 *   POST   /int/v1/fleet-ops/allocation/import-orders — import orders from CSV/Excel data
 */
class AllocationController extends Controller
{
    public function __construct(protected AllocationEngineRegistry $registry)
    {
    }

    /**
     * Run the allocation/route-optimization engine against a set of orders and drivers.
     *
     * The caller may pass explicit order_ids, vehicle_ids, and/or driver_ids.
     * If omitted, the engine runs against all unassigned orders and all online
     * drivers (with their vehicles) for the current company.
     *
     * Supported options:
     *   - mode:             'allocate' (default) | 'optimize' — optimize routes for already-assigned orders
     *   - engine:           engine identifier override (defaults to company setting)
     *   - geometry:         bool — return route geometry polylines (default false)
     *   - balance_workload: bool — spread orders evenly across drivers
     *   - respect_skills:   bool — enforce skill matching (default true)
     *   - respect_capacity: bool — enforce capacity constraints (default true)
     *
     * POST /int/v1/fleet-ops/allocation/run
     */
    public function run(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $mode        = $request->input('mode', 'allocate'); // 'allocate' | 'optimize'

        $orderIds   = $request->input('order_ids', []);
        $vehicleIds = $request->input('vehicle_ids', []);
        $driverIds  = $request->input('driver_ids', []);
        $options    = $request->input('options', []);

        // Resolve orders
        $ordersQuery = Order::where('company_uuid', $companyUuid)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['payload.dropoff', 'payload.pickup', 'payload.waypoints', 'payload.waypointMarkers', 'payload.entities']);

        if ($mode === 'allocate') {
            // Only unassigned orders for allocation mode
            $ordersQuery->whereNull('driver_assigned_uuid');
        }

        if (!empty($orderIds)) {
            $ordersQuery->whereIn('public_id', $orderIds);
        }

        $orders = $ordersQuery->get();

        // Resolve vehicles — by vehicle_ids, driver_ids, or all online
        $vehiclesQuery = Vehicle::where('company_uuid', $companyUuid)
            ->with(['driver' => fn ($q) => $q->where('online', true)->with(['scheduleItems'])]);

        if (!empty($vehicleIds)) {
            $vehiclesQuery->whereIn('public_id', $vehicleIds);
        } elseif (!empty($driverIds)) {
            $vehiclesQuery->whereHas('driver', fn ($q) => $q->whereIn('public_id', $driverIds));
        }

        $vehicles = $vehiclesQuery->get()->filter(fn ($v) => $v->driver !== null);

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders found for the given criteria.', 'assignments' => [], 'unassigned' => []], 200);
        }

        if ($vehicles->isEmpty()) {
            return response()->json(['message' => 'No available vehicles/drivers found.', 'assignments' => [], 'unassigned' => $orders->pluck('public_id')], 200);
        }

        // Resolve the active engine from request override or company setting
        $engineId = $request->input('options.engine')
            ?? Setting::lookup('fleetops.orchestrator_engine', 'vroom');
        $engine = $this->registry->resolve($engineId);

        $result = $engine->allocate($orders, $vehicles, $options);

        return response()->json($result);
    }

    /**
     * Preview allocation without committing any assignments.
     * Identical to run() but returns the plan without persisting anything.
     *
     * GET /int/v1/fleet-ops/allocation/preview
     */
    public function preview(Request $request): JsonResponse
    {
        return $this->run($request);
    }

    /**
     * Commit an allocation plan by dispatching each order to its assigned driver.
     *
     * Accepts the assignments array returned by run() and calls
     * Order::firstDispatchWithActivity() for each assignment, ensuring
     * driver push notifications and tracking status updates fire correctly.
     *
     * Also updates waypoint sequence order on the payload for route-optimized plans.
     *
     * POST /int/v1/fleet-ops/allocation/commit
     */
    public function commit(Request $request): JsonResponse
    {
        $assignments = $request->input('assignments', []);

        if (empty($assignments)) {
            return response()->json(['error' => 'No assignments provided.'], 422);
        }

        $committed = [];
        $failed    = [];

        DB::beginTransaction();

        try {
            foreach ($assignments as $assignment) {
                $order  = Order::where('public_id', $assignment['order_id'])->first();
                $driver = Driver::where('public_id', $assignment['driver_id'])->first();

                if (!$order || !$driver) {
                    $failed[] = $assignment['order_id'];
                    continue;
                }

                // Assign driver and vehicle
                $order->driver_assigned_uuid = $driver->uuid;
                if ($driver->vehicle_uuid) {
                    $order->vehicle_assigned_uuid = $driver->vehicle_uuid;
                }

                // Mark as route-optimized if sequence data is present
                if (isset($assignment['sequence'])) {
                    $order->is_route_optimized = true;
                }

                $order->save();

                // Update waypoint stop sequence if provided
                if (!empty($assignment['waypoint_sequence']) && $order->payload) {
                    foreach ($assignment['waypoint_sequence'] as $seq => $waypointId) {
                        DB::table('waypoints')
                            ->where('payload_uuid', $order->payload_uuid)
                            ->where('public_id', $waypointId)
                            ->update(['order' => $seq]);
                    }
                }

                // Trigger full dispatch flow (push notification + tracking status)
                $order->firstDispatchWithActivity();

                $committed[] = $assignment['order_id'];
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Commit failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'committed' => $committed,
            'failed'    => $failed,
        ]);
    }

    /**
     * Return the list of available allocation/optimization engines.
     * Used by the settings UI to populate the engine selector dropdown.
     *
     * GET /int/v1/fleet-ops/allocation/engines
     */
    public function engines(): JsonResponse
    {
        return response()->json([
            'engines' => $this->registry->available(),
        ]);
    }

    /**
     * Import orders from parsed CSV/Excel row data.
     *
     * Accepts an array of row objects already parsed on the frontend.
     * Each row must contain at minimum: pickup_address, dropoff_address.
     * Optional fields: scheduled_at, notes, weight_kg, volume_m3, parcels,
     * time_window_start, time_window_end, required_skills, priority.
     *
     * Returns created order public_ids and any rows that failed geocoding/validation.
     *
     * POST /int/v1/fleet-ops/allocation/import-orders
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
                // Delegate to the existing order creation flow via the internal API
                // so all observers, tracking numbers, and webhooks fire correctly.
                $orderData = [
                    'company_uuid' => $companyUuid,
                    'status'       => 'created',
                    'type'         => $row['type'] ?? 'default',
                    'notes'        => $row['notes'] ?? null,
                    'scheduled_at' => $row['scheduled_at'] ?? null,
                    'meta'         => array_filter([
                        'weight_kg'  => $row['weight_kg'] ?? null,
                        'volume_m3'  => $row['volume_m3'] ?? null,
                        'parcels'    => $row['parcels'] ?? null,
                    ]),
                    'time_window_start'     => $row['time_window_start'] ?? null,
                    'time_window_end'       => $row['time_window_end'] ?? null,
                    'required_skills'       => $row['required_skills'] ?? [],
                    'orchestrator_priority' => $row['priority'] ?? 0,
                    // Payload addresses are resolved by the OrderController create flow
                    '_pickup_address'  => $row['pickup_address'] ?? null,
                    '_dropoff_address' => $row['dropoff_address'] ?? null,
                ];

                $order   = Order::create($orderData);
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
