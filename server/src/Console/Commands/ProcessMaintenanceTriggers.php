<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * ProcessMaintenanceTriggers
 *
 * Scans all active vehicles and scheduled maintenance records to determine whether
 * preventive maintenance is due based on time intervals or odometer/engine-hour
 * thresholds. When a trigger fires, the maintenance record status is updated to
 * "scheduled" and a `maintenance.triggered` event is dispatched so that downstream
 * extensions (e.g. notification systems, external integrations) can react without
 * requiring changes to this core command.
 *
 * Designed to run on a scheduled basis (e.g. daily via the Laravel scheduler).
 */
class ProcessMaintenanceTriggers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:process-maintenance-triggers
                            {--sandbox : Run in sandbox database mode}
                            {--dry-run : Report triggers without updating records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process preventive maintenance triggers based on time intervals and odometer/engine-hour thresholds';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        date_default_timezone_set('UTC');

        $sandbox = (bool) $this->option('sandbox');
        $dryRun  = (bool) $this->option('dry-run');

        $this->info('Processing maintenance triggers' . ($dryRun ? ' [DRY RUN]' : '') . ' at ' . Carbon::now()->toDateTimeString());

        $triggered = 0;

        // ----------------------------------------------------------------
        // 1. Time-based triggers: scheduled maintenances whose scheduled_at
        //    date has arrived but are still in "scheduled" status.
        // ----------------------------------------------------------------
        $due = Maintenance::on($sandbox ? 'sandbox' : 'mysql')
            ->withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', Carbon::now())
            ->whereNull('deleted_at')
            ->get();

        foreach ($due as $maintenance) {
            $this->line("Time-based trigger: maintenance {$maintenance->public_id} is due (scheduled_at: {$maintenance->scheduled_at})");

            if (!$dryRun) {
                $maintenance->status = 'in_progress';
                $maintenance->save();

                event('maintenance.triggered', $maintenance);
            }

            $triggered++;
        }

        // ----------------------------------------------------------------
        // 2. Odometer / engine-hour threshold triggers: compare the current
        //    vehicle readings against the next_service_odometer and
        //    next_service_engine_hours fields on scheduled maintenances.
        // ----------------------------------------------------------------
        $odometricMaintenances = Maintenance::on($sandbox ? 'sandbox' : 'mysql')
            ->withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->where(function ($query) {
                $query->whereNotNull('next_service_odometer')
                      ->orWhereNotNull('next_service_engine_hours');
            })
            ->whereNotNull('vehicle_uuid')
            ->whereNull('deleted_at')
            ->with('vehicle')
            ->get();

        foreach ($odometricMaintenances as $maintenance) {
            $vehicle = $maintenance->vehicle;

            if (!$vehicle) {
                continue;
            }

            $odometerDue    = $maintenance->next_service_odometer && $vehicle->odometer >= $maintenance->next_service_odometer;
            $engineHoursDue = $maintenance->next_service_engine_hours && $vehicle->engine_hours >= $maintenance->next_service_engine_hours;

            if ($odometerDue || $engineHoursDue) {
                $reason = $odometerDue
                    ? "odometer {$vehicle->odometer} >= threshold {$maintenance->next_service_odometer}"
                    : "engine hours {$vehicle->engine_hours} >= threshold {$maintenance->next_service_engine_hours}";

                $this->line("Threshold trigger: maintenance {$maintenance->public_id} for vehicle {$vehicle->public_id} ({$reason})");

                if (!$dryRun) {
                    $maintenance->status = 'in_progress';
                    $maintenance->save();

                    event('maintenance.triggered', $maintenance);
                }

                $triggered++;
            }
        }

        $this->info("Processed {$triggered} maintenance trigger(s)" . ($dryRun ? ' (dry run — no records updated)' : '.'));
    }
}
