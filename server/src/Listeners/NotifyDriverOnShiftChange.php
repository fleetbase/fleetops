<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\DriverShiftChanged;
use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Listener: NotifyDriverOnShiftChange.
 *
 * Fires after a ScheduleItem is created or updated. Checks the company-level
 * `notify_drivers_on_shift_change` scheduling setting and, if enabled, sends
 * a DriverShiftChanged mail notification to the driver who owns the schedule.
 */
class NotifyDriverOnShiftChange implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event Either ScheduleItemCreated or ScheduleItemUpdated
     */
    public function handle($event): void
    {
        /** @var ScheduleItem $scheduleItem */
        $scheduleItem = $event->scheduleItem ?? null;
        if (!$scheduleItem instanceof ScheduleItem) {
            return;
        }

        // Load the parent schedule to find the subject (driver)
        /** @var Schedule|null $schedule */
        $schedule = $scheduleItem->schedule()->with('subject')->first();
        if (!$schedule) {
            return;
        }

        // Only proceed if the subject is a Driver
        $subject = $schedule->subject;
        if (!$subject instanceof Driver) {
            return;
        }

        // Check the company-level scheduling setting
        $settings     = Setting::lookupFromCompany('fleet-ops.scheduling-settings', []);
        $shouldNotify = (bool) data_get($settings, 'notify_drivers_on_shift_change', false);
        if (!$shouldNotify) {
            return;
        }

        // Determine if this is a new shift or an update
        $isNew = $event instanceof \Fleetbase\Events\ScheduleItemCreated;

        // Send the notification to the driver
        $subject->notify(new DriverShiftChanged($scheduleItem, $isNew));
    }
}
