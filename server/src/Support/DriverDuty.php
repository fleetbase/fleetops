<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\DriverDutyPeriod;
use Illuminate\Support\Carbon;

/**
 * Keeps the record of when a driver is on duty.
 *
 * A driver's `online` flag is written from several places: the app's duty
 * toggle (quietly, so no model event fires), the public update endpoint and the
 * console. Each of them ends up here, so `online_since` and the duty periods
 * agree with the flag whoever changed it.
 */
class DriverDuty
{
    /**
     * Set `online_since` on the model to match its `online` flag. Does not save.
     *
     * A driver already on duty keeps the time they started; going off duty
     * clears it.
     */
    public static function stamp(Driver $driver, ?Carbon $at = null): void
    {
        if ($driver->online) {
            if (!$driver->online_since) {
                $driver->online_since = $at ?? Carbon::now();
            }

            return;
        }

        $driver->online_since = null;
    }

    /**
     * Make the duty periods match the saved driver: one open period while on
     * duty, none while off. Safe to call twice.
     */
    public static function sync(Driver $driver, ?Carbon $at = null): void
    {
        if (!$driver->uuid) {
            return;
        }

        $at   = $at ?? Carbon::now();
        $open = DriverDutyPeriod::where('driver_uuid', $driver->uuid)->whereNull('ended_at');

        if ($driver->online) {
            if ($open->doesntExist()) {
                DriverDutyPeriod::create([
                    'company_uuid' => $driver->company_uuid,
                    'driver_uuid'  => $driver->uuid,
                    'started_at'   => $driver->online_since ?? $at,
                ]);
            }

            return;
        }

        $open->update(['ended_at' => $at]);
    }

    /** Close whatever is open, for a driver who is going away. */
    public static function close(Driver $driver, ?Carbon $at = null): void
    {
        if (!$driver->uuid) {
            return;
        }

        DriverDutyPeriod::where('driver_uuid', $driver->uuid)->whereNull('ended_at')->update(['ended_at' => $at ?? Carbon::now()]);
    }

    /**
     * Seconds on duty inside a window, with the first start and last end seen
     * in it. A period still running counts up to now. Null seconds when no
     * period touches the window: nothing was recorded, which is not the same as
     * zero for a day before duty was being recorded.
     *
     * @return array{seconds: int|null, first_at: Carbon|null, last_at: Carbon|null}
     */
    public static function within(Driver $driver, Carbon $from, Carbon $to): array
    {
        $now     = Carbon::now();
        $periods = DriverDutyPeriod::where('driver_uuid', $driver->uuid)
            ->where('started_at', '<', $to)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->orderBy('started_at')
            ->get();

        if ($periods->isEmpty()) {
            return ['seconds' => null, 'first_at' => null, 'last_at' => null];
        }

        $seconds = 0;
        $first   = null;
        $last    = null;

        foreach ($periods as $period) {
            $start = $period->started_at->greaterThan($from) ? $period->started_at : $from;
            $end   = $period->ended_at ?? $now;
            $end   = $end->lessThan($to) ? $end : $to;

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $seconds += $end->getTimestamp() - $start->getTimestamp();
            $first = $first && $first->lessThan($start) ? $first : $start;
            $last  = $last && $last->greaterThan($end) ? $last : $end;
        }

        return ['seconds' => $seconds, 'first_at' => $first, 'last_at' => $last];
    }
}
