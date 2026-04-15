<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\FleetOps\Jobs\ProcessAllocationJob;
use Fleetbase\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * HandleDeliveryCompletion.
 *
 * Listens for the OrderCompleted event and dispatches a new allocation run
 * when the 'auto_reallocate_on_complete' setting is enabled.
 *
 * This closes the re-allocation loop: as drivers complete deliveries and
 * become available, the engine automatically picks up the next batch of
 * unassigned orders without dispatcher intervention.
 *
 * The listener is registered in EventServiceProvider alongside the existing
 * HandleOrderDispatched and HandleOrderReady listeners.
 */
class HandleDeliveryCompletion implements ShouldQueue
{
    /**
     * Handle the OrderCompleted event.
     */
    public function handle(OrderCompleted $event): void
    {
        $order = $event->order ?? null;

        if (!$order) {
            return;
        }

        $companyUuid = $order->company_uuid;

        // Only re-allocate if the setting is enabled for this company
        if (!Setting::lookup('fleetops.auto_reallocate_on_complete', false)) {
            return;
        }

        // Dispatch the allocation job asynchronously so it does not block
        // the order completion response.
        ProcessAllocationJob::dispatch($companyUuid);
    }
}
