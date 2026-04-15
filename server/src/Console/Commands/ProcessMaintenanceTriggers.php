<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Console\Command;

/**
 * ProcessMaintenanceTriggers.
 *
 * Scans all active MaintenanceSchedule records and determines whether a
 * preventive maintenance work order should be created based on:
 *
 *  - Time/date thresholds  (next_due_date)
 *  - Odometer thresholds   (next_due_odometer vs. current vehicle odometer)
 *  - Engine-hour thresholds (next_due_engine_hours vs. current engine hours)
 *
 * When a schedule is triggered, a WorkOrder is automatically created from the
 * schedule's default settings and a `maintenance.triggered` event is dispatched.
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
                            {--dry-run : Report triggers without creating work orders}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process preventive maintenance schedule triggers and auto-create work orders';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        date_default_timezone_set('UTC');

        $sandbox = (bool) $this->option('sandbox');
        $dryRun  = (bool) $this->option('dry-run');
        $conn    = $sandbox ? 'sandbox' : 'mysql';

        $this->info('Processing maintenance schedule triggers' . ($dryRun ? ' [DRY RUN]' : '') . ' at ' . Carbon::now()->toDateTimeString());

        $triggered = 0;

        // ------------------------------------------------------------------
        // Load all active schedules that have at least one threshold set
        // ------------------------------------------------------------------
        $schedules = MaintenanceSchedule::on($conn)
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNotNull('next_due_date')
                  ->orWhereNotNull('next_due_odometer')
                  ->orWhereNotNull('next_due_engine_hours');
            })
            ->whereNull('deleted_at')
            ->with('subject')
            ->get();

        foreach ($schedules as $schedule) {
            $subject         = $schedule->subject;
            $currentOdometer = null;
            $currentEngHours = null;

            // Resolve current readings from the subject asset if it is a vehicle
            if ($subject instanceof Vehicle) {
                $currentOdometer = $subject->odometer;
                $currentEngHours = $subject->engine_hours;
            }

            if (!$schedule->isDue($currentOdometer, $currentEngHours)) {
                continue;
            }

            // Build a human-readable reason string for logging
            $reasons = [];
            if ($schedule->next_due_date && now()->gte($schedule->next_due_date)) {
                $reasons[] = 'date due ' . $schedule->next_due_date->toDateString();
            }
            if ($schedule->next_due_odometer && $currentOdometer !== null && $currentOdometer >= $schedule->next_due_odometer) {
                $reasons[] = "odometer {$currentOdometer} >= {$schedule->next_due_odometer}";
            }
            if ($schedule->next_due_engine_hours && $currentEngHours !== null && $currentEngHours >= $schedule->next_due_engine_hours) {
                $reasons[] = "engine hours {$currentEngHours} >= {$schedule->next_due_engine_hours}";
            }

            $this->line("Triggered: schedule {$schedule->public_id} ({$schedule->name}) — " . implode(', ', $reasons));

            if (!$dryRun) {
                // Check if a pending work order already exists for this schedule
                // to avoid duplicates on repeated runs before the WO is completed
                $existingOpen = WorkOrder::on($conn)
                    ->withoutGlobalScopes()
                    ->where('schedule_uuid', $schedule->uuid)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->whereNull('deleted_at')
                    ->exists();

                if ($existingOpen) {
                    $this->line('  → Skipped: open work order already exists for this schedule.');
                    continue;
                }

                // Auto-generate a work order from the schedule defaults
                // Generate a sequential WO code: WO-YYYYMMDD-XXXX
                $woCount = WorkOrder::on($conn)->withoutGlobalScopes()->whereNull('deleted_at')->count() + 1;
                $woCode  = 'WO-' . now()->format('Ymd') . '-' . str_pad($woCount, 4, '0', STR_PAD_LEFT);

                $workOrder = WorkOrder::on($conn)->create([
                    'company_uuid'    => $schedule->company_uuid,
                    'schedule_uuid'   => $schedule->uuid,
                    'subject'         => $schedule->name,
                    'code'            => $woCode,
                    'status'          => 'open',
                    'priority'        => $schedule->default_priority ?? 'normal',
                    'target_type'     => $schedule->subject_type,
                    'target_uuid'     => $schedule->subject_uuid,
                    'assignee_type'   => $schedule->default_assignee_type,
                    'assignee_uuid'   => $schedule->default_assignee_uuid,
                    'instructions'    => $schedule->instructions,
                    'due_at'          => $schedule->next_due_date,
                    'opened_at'       => now(),
                    'created_by_uuid' => null, // system-generated
                ]);

                $this->line("  → Created work order {$workOrder->public_id}");

                event('maintenance.triggered', [$schedule, $workOrder]);
            }

            $triggered++;
        }

        $this->info("Processed {$triggered} schedule trigger(s)" . ($dryRun ? ' (dry run — no work orders created)' : '.'));
    }
}
