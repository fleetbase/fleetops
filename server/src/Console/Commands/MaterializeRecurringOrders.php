<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Support\RecurringOrderMaterializationService;
use Illuminate\Console\Command;

class MaterializeRecurringOrders extends Command
{
    protected $signature = 'fleetops:materialize-recurring-orders {--horizon=60 : Days ahead to materialize orders for.}';

    protected $description = 'Materializes recurring order schedules into scheduled FleetOps orders.';

    public function handle(RecurringOrderMaterializationService $service): void
    {
        $horizon = max(1, (int) $this->option('horizon'));
        $stats   = $service->materializeAll($horizon);

        $this->info(sprintf(
            'Recurring order materialization complete. materialized=%d skipped=%d errors=%d',
            $stats['materialized'],
            $stats['skipped'],
            $stats['errors']
        ));
    }
}
