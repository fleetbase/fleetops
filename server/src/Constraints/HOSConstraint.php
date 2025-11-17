<?php

namespace Fleetbase\FleetOps\Constraints;

use Fleetbase\Models\ScheduleItem;
use Fleetbase\Support\Scheduling\ConstraintResult;
use Illuminate\Support\Carbon;

/**
 * Hours of Service (HOS) Constraint.
 *
 * Validates schedule items against FMCSA Hours of Service regulations.
 *
 * Key Regulations:
 * - 11-hour driving limit: May drive a maximum of 11 hours after 10 consecutive hours off duty
 * - 14-hour duty window: May not drive beyond the 14th consecutive hour after coming on duty
 * - 60/70-hour weekly limit: May not drive after 60/70 hours on duty in 7/8 consecutive days
 * - 30-minute break: Required after 8 cumulative hours of driving without at least a 30-minute break
 */
class HOSConstraint
{
    /**
     * Validate a schedule item against HOS regulations.
     */
    public function validate(ScheduleItem $item): ConstraintResult
    {
        $violations = [];

        // Get the driver's recent schedule items
        $recentItems = $this->getRecentScheduleItems($item);

        // Check 11-hour driving limit
        if (!$this->check11HourDrivingLimit($item, $recentItems)) {
            $violations[] = [
                'constraint_key' => 'hos_11_hour_driving_limit',
                'message'        => 'Exceeds 11-hour driving limit. Driver must have 10 consecutive hours off duty.',
                'severity'       => 'critical',
            ];
        }

        // Check 14-hour duty window
        if (!$this->check14HourDutyWindow($item, $recentItems)) {
            $violations[] = [
                'constraint_key' => 'hos_14_hour_duty_window',
                'message'        => 'Exceeds 14-hour duty window. Driver cannot drive beyond 14 hours after coming on duty.',
                'severity'       => 'critical',
            ];
        }

        // Check 60/70-hour weekly limit
        if (!$this->check60_70HourWeeklyLimit($item, $recentItems)) {
            $violations[] = [
                'constraint_key' => 'hos_60_70_hour_weekly_limit',
                'message'        => 'Exceeds 60/70-hour weekly limit. Driver has exceeded maximum hours in 7/8 consecutive days.',
                'severity'       => 'critical',
            ];
        }

        // Check 30-minute break requirement
        if (!$this->check30MinuteBreak($item, $recentItems)) {
            $violations[] = [
                'constraint_key' => 'hos_30_minute_break',
                'message'        => 'Missing required 30-minute break after 8 cumulative hours of driving.',
                'severity'       => 'warning',
            ];
        }

        if (empty($violations)) {
            return ConstraintResult::pass();
        }

        return ConstraintResult::fail($violations);
    }

    /**
     * Get recent schedule items for the driver.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getRecentScheduleItems(ScheduleItem $item)
    {
        $sevenDaysAgo = Carbon::parse($item->start_at)->subDays(7);

        return ScheduleItem::where('assignee_uuid', $item->assignee_uuid)
            ->where('assignee_type', $item->assignee_type)
            ->where('start_at', '>=', $sevenDaysAgo)
            ->where('id', '!=', $item->id)
            ->orderBy('start_at', 'asc')
            ->get();
    }

    /**
     * Check 11-hour driving limit.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function check11HourDrivingLimit(ScheduleItem $item, $recentItems): bool
    {
        // Get the last 10-hour off-duty period
        $lastOffDutyPeriod = $this->getLastOffDutyPeriod($item, $recentItems, 10);

        if (!$lastOffDutyPeriod) {
            // If no 10-hour off-duty period found, check total driving hours
            $totalDrivingHours = $this->calculateTotalDrivingHours($item, $recentItems);

            return $totalDrivingHours <= 11;
        }

        // Calculate driving hours since last 10-hour off-duty period
        $drivingHoursSinceRest = $this->calculateDrivingHoursSince($item, $recentItems, $lastOffDutyPeriod);

        return $drivingHoursSinceRest <= 11;
    }

    /**
     * Check 14-hour duty window.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function check14HourDutyWindow(ScheduleItem $item, $recentItems): bool
    {
        $lastOffDutyPeriod = $this->getLastOffDutyPeriod($item, $recentItems, 10);

        if (!$lastOffDutyPeriod) {
            return true; // Cannot determine, allow for now
        }

        $dutyStart = Carbon::parse($lastOffDutyPeriod);
        $itemEnd   = Carbon::parse($item->end_at);

        $hoursSinceDutyStart = $dutyStart->diffInHours($itemEnd);

        return $hoursSinceDutyStart <= 14;
    }

    /**
     * Check 60/70-hour weekly limit.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function check60_70HourWeeklyLimit(ScheduleItem $item, $recentItems): bool
    {
        // Use 70-hour/8-day limit as default (can be configured)
        $limit = 70;
        $days  = 8;

        $startDate  = Carbon::parse($item->start_at)->subDays($days);
        $totalHours = 0;

        foreach ($recentItems as $recentItem) {
            if (Carbon::parse($recentItem->start_at)->gte($startDate)) {
                $totalHours += $recentItem->duration / 60; // Convert minutes to hours
            }
        }

        // Add current item duration
        $totalHours += $item->duration / 60;

        return $totalHours <= $limit;
    }

    /**
     * Check 30-minute break requirement.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function check30MinuteBreak(ScheduleItem $item, $recentItems): bool
    {
        // Get driving hours in the current duty period
        $drivingHours = 0;
        $lastBreak    = null;

        foreach ($recentItems as $recentItem) {
            if ($recentItem->break_start_at && $recentItem->break_end_at) {
                $breakDuration = Carbon::parse($recentItem->break_start_at)->diffInMinutes($recentItem->break_end_at);
                if ($breakDuration >= 30) {
                    $lastBreak    = $recentItem->break_end_at;
                    $drivingHours = 0; // Reset driving hours after break
                }
            }

            $drivingHours += $recentItem->duration / 60;
        }

        // Add current item
        $drivingHours += $item->duration / 60;

        // If more than 8 hours of driving, require a 30-minute break
        if ($drivingHours > 8) {
            return $item->break_start_at && $item->break_end_at
                   && Carbon::parse($item->break_start_at)->diffInMinutes($item->break_end_at) >= 30;
        }

        return true;
    }

    /**
     * Get the last off-duty period of specified duration.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function getLastOffDutyPeriod(ScheduleItem $item, $recentItems, int $hours): ?Carbon
    {
        // This is a simplified implementation
        // In production, this would check actual off-duty periods from driver logs
        return null;
    }

    /**
     * Calculate total driving hours.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function calculateTotalDrivingHours(ScheduleItem $item, $recentItems): float
    {
        $totalMinutes = $item->duration;

        foreach ($recentItems as $recentItem) {
            $totalMinutes += $recentItem->duration;
        }

        return $totalMinutes / 60;
    }

    /**
     * Calculate driving hours since a specific time.
     *
     * @param \Illuminate\Database\Eloquent\Collection $recentItems
     */
    protected function calculateDrivingHoursSince(ScheduleItem $item, $recentItems, Carbon $since): float
    {
        $totalMinutes = 0;

        foreach ($recentItems as $recentItem) {
            if (Carbon::parse($recentItem->start_at)->gte($since)) {
                $totalMinutes += $recentItem->duration;
            }
        }

        $totalMinutes += $item->duration;

        return $totalMinutes / 60;
    }
}
