<?php

use Fleetbase\FleetOps\Constraints\HOSConstraint;
use Fleetbase\Models\ScheduleItem;
use Illuminate\Support\Carbon;

function invokeFleetOpsHosConstraint(HOSConstraint $constraint, string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($constraint, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($constraint, $arguments);
}

function fleetOpsScheduleItem(array $attributes): ScheduleItem
{
    $item = new ScheduleItem();
    $item->setRawAttributes($attributes, true);

    return $item;
}

function fleetOpsRecentScheduleItem(array $attributes): object
{
    return (object) $attributes;
}

test('hos constraint calculates driving hours across current and recent schedule items', function () {
    $constraint = new HOSConstraint();
    $current    = fleetOpsScheduleItem([
        'duration' => 120,
        'start_at' => '2026-07-19 12:00:00',
    ]);
    $recent     = collect([
        fleetOpsRecentScheduleItem(['duration' => 90, 'start_at' => '2026-07-19 08:00:00']),
        fleetOpsRecentScheduleItem(['duration' => 150, 'start_at' => '2026-07-19 10:00:00']),
    ]);

    expect(invokeFleetOpsHosConstraint($constraint, 'calculateTotalDrivingHours', [$current, $recent]))
        ->toBe(6.0)
        ->and(invokeFleetOpsHosConstraint($constraint, 'calculateDrivingHoursSince', [
            $current,
            $recent,
            Carbon::parse('2026-07-19 09:00:00'),
        ]))->toBe(4.5);
});

test('hos constraint enforces driving and weekly hour limits', function () {
    $constraint = new HOSConstraint();
    $current    = fleetOpsScheduleItem([
        'duration' => 120,
        'start_at' => '2026-07-19 12:00:00',
    ]);

    $withinLimit = collect([
        fleetOpsRecentScheduleItem(['duration' => 300, 'start_at' => '2026-07-18 08:00:00']),
        fleetOpsRecentScheduleItem(['duration' => 180, 'start_at' => '2026-07-17 08:00:00']),
    ]);

    $overLimit = collect([
        fleetOpsRecentScheduleItem(['duration' => 600, 'start_at' => '2026-07-18 08:00:00']),
        fleetOpsRecentScheduleItem(['duration' => 120, 'start_at' => '2026-07-17 08:00:00']),
    ]);

    $weeklyOverLimit = collect([
        fleetOpsRecentScheduleItem(['duration' => 4200, 'start_at' => '2026-07-18 08:00:00']),
        fleetOpsRecentScheduleItem(['duration' => 120, 'start_at' => '2026-07-01 08:00:00']),
    ]);

    expect(invokeFleetOpsHosConstraint($constraint, 'check11HourDrivingLimit', [$current, $withinLimit]))->toBeTrue()
        ->and(invokeFleetOpsHosConstraint($constraint, 'check11HourDrivingLimit', [$current, $overLimit]))->toBeFalse()
        ->and(invokeFleetOpsHosConstraint($constraint, 'check60_70HourWeeklyLimit', [$current, $withinLimit]))->toBeTrue()
        ->and(invokeFleetOpsHosConstraint($constraint, 'check60_70HourWeeklyLimit', [$current, $weeklyOverLimit]))->toBeFalse();
});

test('hos constraint handles duty windows and thirty minute break requirements', function () {
    $constraint = new HOSConstraint();
    $current    = fleetOpsScheduleItem([
        'duration'       => 120,
        'start_at'       => '2026-07-19 12:00:00',
        'end_at'         => '2026-07-19 14:00:00',
        'break_start_at' => null,
        'break_end_at'   => null,
    ]);

    $recentWithBreak = collect([
        fleetOpsRecentScheduleItem([
            'duration'       => 420,
            'start_at'       => '2026-07-19 05:00:00',
            'break_start_at' => '2026-07-19 08:00:00',
            'break_end_at'   => '2026-07-19 08:30:00',
        ]),
    ]);

    $recentWithoutBreak = collect([
        fleetOpsRecentScheduleItem([
            'duration'       => 420,
            'start_at'       => '2026-07-19 05:00:00',
            'break_start_at' => null,
            'break_end_at'   => null,
        ]),
    ]);

    $currentWithBreak = fleetOpsScheduleItem([
        'duration'       => 120,
        'start_at'       => '2026-07-19 12:00:00',
        'end_at'         => '2026-07-19 14:00:00',
        'break_start_at' => '2026-07-19 12:30:00',
        'break_end_at'   => '2026-07-19 13:00:00',
    ]);

    expect(invokeFleetOpsHosConstraint($constraint, 'check14HourDutyWindow', [$current, collect()]))->toBeTrue()
        ->and(invokeFleetOpsHosConstraint($constraint, 'check30MinuteBreak', [$current, $recentWithBreak]))->toBeTrue()
        ->and(invokeFleetOpsHosConstraint($constraint, 'check30MinuteBreak', [$current, $recentWithoutBreak]))->toBeFalse()
        ->and(invokeFleetOpsHosConstraint($constraint, 'check30MinuteBreak', [$currentWithBreak, $recentWithoutBreak]))->toBeTrue();
});
