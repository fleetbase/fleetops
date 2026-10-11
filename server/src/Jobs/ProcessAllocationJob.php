<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings;
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
        $orders = $this->unassignedOrders();

        if ($orders->isEmpty()) {
            $this->logInfo("[ProcessAllocationJob] No unassigned orders for company {$this->companyUuid}.");

            return;
        }

        $vehicles = $this->availableVehicles();

        if ($vehicles->isEmpty()) {
            $this->logInfo("[ProcessAllocationJob] No available vehicles for company {$this->companyUuid}.");

            return;
        }

        $result = $registry->allocateWithFallback($this->engineId(), $orders, $vehicles, $this->allocationOptions());

        if (!empty($result['summary']['fallback_reason'])) {
            $this->logInfo(sprintf(
                '[ProcessAllocationJob] Used the %s engine instead of %s for company %s: %s',
                $result['summary']['engine'],
                $result['summary']['requested_engine'],
                $this->companyUuid,
                $result['summary']['fallback_reason']
            ));
        }

        foreach ($result['assignments'] as $assignment) {
            $order  = $this->findOrderByPublicId($assignment['order_id']);
            $driver = $this->findDriverByPublicId($assignment['driver_id']);

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

        $this->logInfo(
            sprintf(
                '[ProcessAllocationJob] Committed %d assignments, %d unassigned for company %s.',
                count($result['assignments']),
                count($result['unassigned']),
                $this->companyUuid
            )
        );
    }

    protected function unassignedOrders()
    {
        $ordersQuery = Order::where('company_uuid', $this->companyUuid)
            ->whereNull('driver_assigned_uuid')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['payload.dropoff', 'payload.waypoints']);

        if (!empty($this->orderIds)) {
            $ordersQuery->whereIn('public_id', $this->orderIds);
        }

        return $ordersQuery->get();
    }

    protected function availableVehicles()
    {
        return Vehicle::where('company_uuid', $this->companyUuid)
            ->with(['driver' => fn ($q) => $q->where('online', true)])
            ->get()
            ->filter(fn ($v) => $v->driver !== null);
    }

    protected function engineId(): string
    {
        return OrchestratorSettings::engineForCompany($this->companyUuid);
    }

    protected function allocationOptions(): array
    {
        $settings = OrchestratorSettings::forCompany($this->companyUuid);

        return [
            'max_travel_time'  => (int) $settings['max_travel_time_seconds'],
            'balance_workload' => (bool) $settings['balance_workload'],
        ];
    }

    protected function findOrderByPublicId(string $publicId): ?Order
    {
        return Order::where('public_id', $publicId)->first();
    }

    protected function findDriverByPublicId(string $publicId): ?Driver
    {
        return Driver::where('public_id', $publicId)->first();
    }

    protected function logInfo(string $message): void
    {
        Log::info($message);
    }
}
