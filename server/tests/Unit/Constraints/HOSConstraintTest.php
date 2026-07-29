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

test('recent schedule items query scopes assignee and rolling window', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    $schema->create('schedule_items', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'schedule_uuid', 'assignee_type', 'assignee_uuid', 'status', 'start_at', 'end_at', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('schedule_items')->insert([
        ['uuid' => 'shift-hos-current', 'assignee_type' => 'driver', 'assignee_uuid' => 'driver-hos-1', 'start_at' => '2026-07-27 08:00:00', 'end_at' => '2026-07-27 16:00:00'],
        ['uuid' => 'shift-hos-recent', 'assignee_type' => 'driver', 'assignee_uuid' => 'driver-hos-1', 'start_at' => '2026-07-25 08:00:00', 'end_at' => '2026-07-25 16:00:00'],
        ['uuid' => 'shift-hos-old', 'assignee_type' => 'driver', 'assignee_uuid' => 'driver-hos-1', 'start_at' => '2026-07-01 08:00:00', 'end_at' => '2026-07-01 16:00:00'],
        ['uuid' => 'shift-hos-other', 'assignee_type' => 'driver', 'assignee_uuid' => 'driver-hos-2', 'start_at' => '2026-07-26 08:00:00', 'end_at' => '2026-07-26 16:00:00'],
    ]);

    $current    = ScheduleItem::where('uuid', 'shift-hos-current')->first();
    $constraint = new HOSConstraint();
    $reflection = new ReflectionMethod(HOSConstraint::class, 'getRecentScheduleItems');
    $reflection->setAccessible(true);
    $recent = $reflection->invoke($constraint, $current);

    expect($recent)->toHaveCount(1)
        ->and($recent->first()->uuid)->toBe('shift-hos-recent');
});
