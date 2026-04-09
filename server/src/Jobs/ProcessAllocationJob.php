<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessAllocationJob.
 *
 * Dispatched asynchronously when auto-allocation is triggered (e.g. on order
 * creation or on delivery completion when auto_reallocate_on_complete is
 * enabled). The job runs the active allocation engine and commits all
 * assignments automatically.
 *
 * The job is idempotent — if an order has already been assigned by the time
 * the job runs, it is silently skipped.
 *
 * @example Dispatching from a listener:
 *   ProcessAllocationJob::dispatch($companyUuid, $orderIds);
 */
class ProcessAllocationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of retry attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * @param string $companyUuid the company to run allocation for
     * @param array  $orderIds    Optional list of order public_ids to allocate.
     *                            If empty, all unassigned orders are used.
     */
    public function __construct(
        protected string $companyUuid,
        protected array $orderIds = [],
    ) {
    }

    public function handle(OrchestrationEngineRegistry $registry): void
    {
        $ordersQuery = Order::where('company_uuid', $this->companyUuid)
            ->whereNull('driver_assigned_uuid')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['payload.dropoff', 'payload.waypoints']);

        if (!empty($this->orderIds)) {
            $ordersQuery->whereIn('public_id', $this->orderIds);
        }

        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            Log::info("[ProcessAllocationJob] No unassigned orders for company {$this->companyUuid}.");

            return;
        }

        $vehicles = Vehicle::where('company_uuid', $this->companyUuid)
            ->with(['driver' => fn ($q) => $q->where('online', true)])
            ->get()
            ->filter(fn ($v) => $v->driver !== null);

        if ($vehicles->isEmpty()) {
            Log::info("[ProcessAllocationJob] No available vehicles for company {$this->companyUuid}.");

            return;
        }

        $engineId = Setting::lookup('fleetops.orchestrator_engine', 'greedy');
        $engine   = $registry->resolve($engineId);

        $result = $engine->allocate($orders, $vehicles, [
            'max_travel_time'  => Setting::lookup('fleetops.allocation_max_travel_time', 3600),
            'balance_workload' => Setting::lookup('fleetops.allocation_balance_workload', false),
        ]);

        foreach ($result['assignments'] as $assignment) {
            $order  = Order::where('public_id', $assignment['order_id'])->first();
            $driver = Driver::where('public_id', $assignment['driver_id'])->first();

            if (!$order || !$driver) {
                continue;
            }

            // Skip if already assigned (idempotency guard)
            if ($order->driver_assigned_uuid) {
                continue;
            }

            $order->driver_assigned_uuid = $driver->uuid;
            $order->save();
            $order->firstDispatchWithActivity();
        }

        Log::info(
            sprintf(
                '[ProcessAllocationJob] Committed %d assignments, %d unassigned for company %s.',
                count($result['assignments']),
                count($result['unassigned']),
                $this->companyUuid
            )
        );
    }
}
