<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Mail\MaintenanceScheduleReminder;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends reminder emails for upcoming maintenance schedules.
 *
 * Runs daily. For each active schedule that has reminder_offsets configured,
 * it checks whether any offset threshold has been crossed (i.e. today is within
 * N days of next_due_date) and — if no reminder has already been sent for that
 * offset + due-date combination — sends a MaintenanceScheduleReminder email to
 * the default assignee and records the send in maintenance_schedule_reminders.
 */
class SendMaintenanceReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:send-maintenance-reminders
                            {--sandbox : Run against the sandbox database connection}
                            {--dry-run : Log which emails would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminder emails for maintenance schedules that are approaching their due date';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        date_default_timezone_set('UTC');
        $sandbox = (bool) $this->option('sandbox');
        $dryRun  = (bool) $this->option('dry-run');
        $conn    = $sandbox ? 'sandbox' : 'mysql';

        $this->info('Sending maintenance schedule reminders' . ($dryRun ? ' [DRY RUN]' : '') . ' at ' . Carbon::now()->toDateTimeString());

        $sent = 0;

        // Load all active schedules that have:
        //   - a non-null next_due_date (reminders are date-based only)
        //   - a non-null reminder_offsets JSON array
        //   - a default_assignee configured
        $schedules = MaintenanceSchedule::on($conn)
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->whereNotNull('reminder_offsets')
            ->whereNotNull('default_assignee_uuid')
            ->whereNull('deleted_at')
            ->with(['subject', 'defaultAssignee'])
            ->get();

        foreach ($schedules as $schedule) {
            $offsets     = $schedule->reminder_offsets;
            $nextDueDate = $schedule->next_due_date;

            if (empty($offsets) || !$nextDueDate) {
                continue;
            }

            // Resolve the recipient email from the polymorphic defaultAssignee
            $assignee = $schedule->defaultAssignee;
            $email    = $assignee?->email ?? null;

            if (!$email) {
                $this->line("  → Skipped schedule {$schedule->public_id}: no email on default assignee.");
                continue;
            }

            $dueDateSnapshot = $nextDueDate->toDateString();

            foreach ($offsets as $offsetDays) {
                $offsetDays = (int) $offsetDays;

                // The reminder window: today must be on or after (due_date - offset_days)
                $reminderDate = $nextDueDate->copy()->subDays($offsetDays);

                if (Carbon::today()->lt($reminderDate)) {
                    // Not yet within the reminder window for this offset
                    continue;
                }

                // Check whether we already sent this reminder for this cycle
                $alreadySent = DB::connection($conn)
                    ->table('maintenance_schedule_reminders')
                    ->where('schedule_uuid', $schedule->uuid)
                    ->where('offset_days', $offsetDays)
                    ->where('due_date_snapshot', $dueDateSnapshot)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $this->line("Sending reminder: schedule {$schedule->public_id} ({$schedule->name}) — {$offsetDays} days before {$dueDateSnapshot} → {$email}");

                if (!$dryRun) {
                    Mail::to($email)->send(new MaintenanceScheduleReminder($schedule, $offsetDays));

                    DB::connection($conn)->table('maintenance_schedule_reminders')->insert([
                        'schedule_uuid'     => $schedule->uuid,
                        'offset_days'       => $offsetDays,
                        'due_date_snapshot' => $dueDateSnapshot,
                        'sent_at'           => Carbon::now(),
                    ]);
                }

                $sent++;
            }
        }

        $this->info("Sent {$sent} reminder(s)" . ($dryRun ? ' (dry run — no emails sent)' : '.'));
    }
}
