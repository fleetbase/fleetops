<?php

use Fleetbase\FleetOps\Models\RecurringOrderSchedule;
use Illuminate\Support\Carbon;

it('previews weekly recurring occurrences from an rrule', function () {
    $schedule = new RecurringOrderSchedule([
        'timezone' => 'Asia/Singapore',
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Asia/Singapore'),
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE',
    ]);

    $occurrences = $schedule->previewOccurrences(
        Carbon::parse('2026-05-01 00:00:00', 'Asia/Singapore'),
        Carbon::parse('2026-05-20 23:59:59', 'Asia/Singapore'),
        4
    );

    expect($occurrences->map(fn (Carbon $occurrence) => $occurrence->format('Y-m-d H:i'))->all())
        ->toBe([
            '2026-05-04 09:00',
            '2026-05-06 09:00',
            '2026-05-11 09:00',
            '2026-05-13 09:00',
        ]);
});

it('previews monthly recurring occurrences with monthday', function () {
    $schedule = new RecurringOrderSchedule([
        'timezone' => 'UTC',
        'starts_at' => Carbon::parse('2026-01-15 08:30:00', 'UTC'),
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=15',
    ]);

    $occurrences = $schedule->previewOccurrences(
        Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        Carbon::parse('2026-04-30 23:59:59', 'UTC'),
        4
    );

    expect($occurrences->map(fn (Carbon $occurrence) => $occurrence->format('Y-m-d H:i'))->all())
        ->toBe([
            '2026-01-15 08:30',
            '2026-02-15 08:30',
            '2026-03-15 08:30',
            '2026-04-15 08:30',
        ]);
});
