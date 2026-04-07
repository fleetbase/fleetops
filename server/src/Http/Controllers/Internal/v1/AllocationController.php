<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Allocation\AllocationEngineRegistry;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AllocationController
 *
 * Provides the HTTP interface for the Intelligent Order Allocation Engine.
 * All routes are internal (require fleetbase.protected middleware).
 *
 * Endpoints:
 *   POST   /int/v1/fleet-ops/allocation/run          — run allocation
 *   POST   /int/v1/fleet-ops/allocation/commit        — commit an allocation plan
 *   GET    /int/v1/fleet-ops/allocation/preview       — preview without committing
 *   GET    /int/v1/fleet-ops/allocation/engines       — list available engines
 *   GET    /int/v1/fleet-ops/allocation/settings      — get allocation settings
 *   PATCH  /int/v1/fleet-ops/allocation/settings      — save allocation settings
 */
class AllocationController extends FleetOpsController
{
    public function __construct(protected AllocationEngineRegistry $registry)
    {
        parent::__construct();
    }

    /**
     * Run the allocation engine against a set of orders and vehicles.
     *
     * The caller may pass explicit order_ids and vehicle_ids. If omitted,
     * the engine runs against all unassigned orders and all online vehicles
     * for the current company.
     *
     * POST /int/v1/fleet-ops/allocation/run
     */
    public function run(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $orderIds   = $request->input('order_ids', []);
        $vehicleIds = $request->input('vehicle_ids', []);
        $options    = $request->input('options', []);

        // Resolve orders — default to all unassigned, non-cancelled orders
        $ordersQuery = Order::where('company_uuid', $companyUuid)
            ->whereNull('driver_assigned_uuid')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['payload.dropoff', 'payload.waypoints']);

        if (!empty($orderIds)) {
            $ordersQuery->whereIn('public_id', $orderIds);
        }

        $orders = $ordersQuery->get();

        // Resolve vehicles — default to all online vehicles with loaded drivers
        $vehiclesQuery = Vehicle::where('company_uuid', $companyUuid)
            ->with(['driver' => fn ($q) => $q->where('online', true)]);

        if (!empty($vehicleIds)) {
            $vehiclesQuery->whereIn('public_id', $vehicleIds);
        }

        $vehicles = $vehiclesQuery->get()->filter(fn ($v) => $v->driver !== null);

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No unassigned orders found.', 'assignments' => [], 'unassigned' => []], 200);
        }

        if ($vehicles->isEmpty()) {
            return response()->json(['message' => 'No available vehicles/drivers found.', 'assignments' => [], 'unassigned' => $orders->pluck('public_id')], 200);
        }

        // Resolve the active engine from settings (default: vroom)
        $engineId = Setting::lookup('fleetops.allocation_engine', 'vroom');
        $engine   = $this->registry->resolve($engineId);

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
        // Preview is functionally identical to run — no side effects are
        // triggered here. The commit endpoint handles persistence.
        return $this->run($request);
    }

    /**
     * Commit an allocation plan by dispatching each order to its assigned driver.
     *
     * Accepts the assignments array returned by run() and calls
     * Order::firstDispatchWithActivity() for each assignment, ensuring
     * driver push notifications and tracking status updates fire correctly.
     *
     * POST /int/v1/fleet-ops/allocation/commit
     */
    public function commit(Request $request): JsonResponse
    {
        $assignments = $request->input('assignments', []);

        if (empty($assignments)) {
            return response()->json(['error' => 'No assignments provided.'], 422);
        }

        $committed  = [];
        $failed     = [];

        DB::beginTransaction();

        try {
            foreach ($assignments as $assignment) {
                $order  = Order::where('public_id', $assignment['order_id'])->first();
                $driver = Driver::where('public_id', $assignment['driver_id'])->first();

                if (!$order || !$driver) {
                    $failed[] = $assignment['order_id'];
                    continue;
                }

                // Assign driver and dispatch — this triggers the full dispatch
                // flow including driver push notification and tracking status.
                $order->driver_assigned_uuid = $driver->uuid;
                $order->save();
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
     * Return the list of available allocation engines.
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
     * Return the current allocation settings for the company.
     *
     * GET /int/v1/fleet-ops/allocation/settings
     */
    public function getSettings(): JsonResponse
    {
        return response()->json([
            'allocation_engine'       => Setting::lookup('fleetops.allocation_engine', 'vroom'),
            'auto_allocate_on_create' => Setting::lookup('fleetops.auto_allocate_on_create', false),
            'auto_reallocate_on_complete' => Setting::lookup('fleetops.auto_reallocate_on_complete', false),
            'max_travel_time_seconds' => Setting::lookup('fleetops.allocation_max_travel_time', 3600),
            'balance_workload'        => Setting::lookup('fleetops.allocation_balance_workload', false),
        ]);
    }

    /**
     * Save allocation settings for the company.
     *
     * PATCH /int/v1/fleet-ops/allocation/settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $allowed = [
            'allocation_engine'           => 'fleetops.allocation_engine',
            'auto_allocate_on_create'     => 'fleetops.auto_allocate_on_create',
            'auto_reallocate_on_complete' => 'fleetops.auto_reallocate_on_complete',
            'max_travel_time_seconds'     => 'fleetops.allocation_max_travel_time',
            'balance_workload'            => 'fleetops.allocation_balance_workload',
        ];

        foreach ($allowed as $inputKey => $settingKey) {
            if ($request->has($inputKey)) {
                Setting::configure($settingKey, $request->input($inputKey));
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
