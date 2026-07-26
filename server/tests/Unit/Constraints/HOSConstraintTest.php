<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Constraints\HOSConstraint;
use Fleetbase\Models\ScheduleItem;
use Illuminate\Support\Carbon;

class FleetOpsUnitHosScheduleItemFake extends ScheduleItem
{
    public int $duration;
    public string $start_at;
    public ?string $end_at         = null;
    public ?string $break_start_at = null;
    public ?string $break_end_at   = null;
}

class FleetOpsUnitHosConstraintProbe extends HOSConstraint
{
    public $recentItems;
    public ?Carbon $lastOffDutyPeriod = null;

    protected function getRecentScheduleItems(ScheduleItem $item)
    {
        return $this->recentItems ?? collect();
    }

    protected function getLastOffDutyPeriod(ScheduleItem $item, $recentItems, int $hours): ?Carbon
    {
        return $this->lastOffDutyPeriod;
    }
}

function fleetopsUnitHosScheduleItem(array $attributes): ScheduleItem
{
    $item = new FleetOpsUnitHosScheduleItemFake();

    foreach ($attributes as $key => $value) {
        $item->{$key} = $value;
    }

    return $item;
}

function fleetopsUnitHosRecentItem(array $attributes): object
{
    return (object) $attributes;
}

test('hos constraint validation passes when schedule item remains inside all limits', function () {
    $constraint              = new FleetOpsUnitHosConstraintProbe();
    $constraint->recentItems = collect([
        fleetopsUnitHosRecentItem([
            'duration'       => 120,
            'start_at'       => '2026-07-18 08:00:00',
            'break_start_at' => null,
            'break_end_at'   => null,
        ]),
        fleetopsUnitHosRecentItem([
            'duration'       => 180,
            'start_at'       => '2026-07-19 08:00:00',
            'break_start_at' => null,
            'break_end_at'   => null,
        ]),
    ]);

    $result = $constraint->validate(fleetopsUnitHosScheduleItem([
        'id'             => 22,
        'assignee_uuid'  => 'driver-1',
        'assignee_type'  => 'driver',
        'duration'       => 60,
        'start_at'       => '2026-07-19 12:00:00',
        'end_at'         => '2026-07-19 13:00:00',
        'break_start_at' => null,
        'break_end_at'   => null,
    ]));

    expect($result->passed())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->getViolations())->toBe([]);
});

test('hos constraint validation returns every violation when limits are exceeded', function () {
    $constraint                    = new FleetOpsUnitHosConstraintProbe();
    $constraint->lastOffDutyPeriod = Carbon::parse('2026-07-19 00:00:00');
    $constraint->recentItems       = collect([
        fleetopsUnitHosRecentItem([
            'duration'       => 4200,
            'start_at'       => '2026-07-18 08:00:00',
            'break_start_at' => null,
            'break_end_at'   => null,
        ]),
        fleetopsUnitHosRecentItem([
            'duration'       => 600,
            'start_at'       => '2026-07-19 03:00:00',
            'break_start_at' => null,
            'break_end_at'   => null,
        ]),
    ]);

    $result = $constraint->validate(fleetopsUnitHosScheduleItem([
        'id'             => 23,
        'assignee_uuid'  => 'driver-1',
        'assignee_type'  => 'driver',
        'duration'       => 120,
        'start_at'       => '2026-07-19 13:30:00',
        'end_at'         => '2026-07-19 15:30:00',
        'break_start_at' => null,
        'break_end_at'   => null,
    ]));

    expect($result->failed())->toBeTrue()
        ->and(collect($result->getViolations())->pluck('constraint_key')->all())->toBe([
            'hos_11_hour_driving_limit',
            'hos_14_hour_duty_window',
            'hos_60_70_hour_weekly_limit',
            'hos_30_minute_break',
        ])
        ->and(collect($result->getViolations())->pluck('severity')->all())->toBe([
            'critical',
            'critical',
            'critical',
            'warning',
        ]);
});
