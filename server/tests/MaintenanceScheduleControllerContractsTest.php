<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MaintenanceScheduleController;
use Illuminate\Support\Carbon;

class FleetOpsMaintenanceScheduleControllerProbe extends MaintenanceScheduleController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MaintenanceScheduleController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsMaintenanceSchedule(array $attributes, ?object $subject = null, ?object $assignee = null): object
{
    return (object) [
        ...$attributes,
        'uuid'            => $attributes['uuid'] ?? 'schedule-uuid',
        'public_id'       => $attributes['public_id'] ?? 'schedule_public',
        'subject'         => $subject,
        'defaultAssignee' => $assignee,
    ];
}

test('maintenance schedule controller expands recurring calendar events inside window', function () {
    $controller = new FleetOpsMaintenanceScheduleControllerProbe();
    $schedule   = fleetopsMaintenanceSchedule([
        'uuid'             => 'schedule-uuid',
        'public_id'        => 'schedule_public',
        'name'             => 'Quarterly inspection',
        'status'           => 'active',
        'type'             => 'inspection',
        'default_priority' => 'high',
        'interval_value'   => 2,
        'interval_unit'    => 'days',
        'next_due_date'    => Carbon::parse('2026-01-01'),
    ], (object) ['display_name' => 'Truck 9'], (object) ['name' => 'Vendor Ops']);

    $events = $controller->callHelper(
        'calendarEventsForSchedules',
        [$schedule],
        Carbon::parse('2026-01-02')->startOfDay(),
        Carbon::parse('2026-01-07')->endOfDay()
    );

    expect(array_column($events, 'occurrence_date'))->toBe(['2026-01-03', '2026-01-05', '2026-01-07'])
        ->and($events[0])->toMatchArray([
            'id'            => 'schedule_public',
            'uuid'          => 'schedule-uuid',
            'title'         => 'Quarterly inspection — Truck 9',
            'allDay'        => true,
            'status'        => 'active',
            'priority'      => 'high',
            'type'          => 'inspection',
            'subject_name'  => 'Truck 9',
            'assignee_name' => 'Vendor Ops',
            'color'         => '#f97316',
            'start'         => '2026-01-03',
            'end'           => '2026-01-03',
        ]);
});

test('maintenance schedule controller emits one-off events only inside window', function () {
    $controller = new FleetOpsMaintenanceScheduleControllerProbe();
    $inside     = fleetopsMaintenanceSchedule([
        'name'             => 'Annual service',
        'status'           => 'active',
        'type'             => 'service',
        'default_priority' => 'low',
        'next_due_date'    => Carbon::parse('2026-02-10'),
    ], (object) ['public_id' => 'asset_public']);
    $outside = fleetopsMaintenanceSchedule([
        'name'             => 'Outside service',
        'status'           => 'active',
        'type'             => 'service',
        'default_priority' => 'critical',
        'next_due_date'    => Carbon::parse('2026-03-10'),
    ]);

    $events = $controller->callHelper(
        'calendarEventsForSchedules',
        [$inside, $outside],
        Carbon::parse('2026-02-01')->startOfDay(),
        Carbon::parse('2026-02-28')->endOfDay()
    );

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'title'        => 'Annual service — asset_public',
            'subject_name' => 'asset_public',
            'color'        => '#22c55e',
            'start'        => '2026-02-10',
            'end'          => '2026-02-10',
        ])
        ->and($controller->callHelper('eventColorForPriority', 'critical'))->toBe('#ef4444')
        ->and($controller->callHelper('eventColorForPriority', 'normal'))->toBe('#3b82f6')
        ->and($controller->callHelper('eventColorForPriority', 'unknown'))->toBe('#6b7280');
});
