<?php

namespace Fleetbase\FleetOps\Support\Dispatch;

use Illuminate\Support\Carbon;

/**
 * The day a plan or route is for, as `Y-m-d`.
 *
 * Strict on purpose: `2026-11-31` or `tomorrow` would otherwise be read by
 * Carbon as some other day, and a route dated for the wrong day reaches the
 * wrong shift without anyone noticing.
 */
class DispatchDate
{
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * A moment sent by a client (ISO 8601 or anything Carbon reads), or null.
     */
    public static function moment(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
