<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MaintenanceScheduleController;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsInternalMaintenanceScheduleControllerProbe extends MaintenanceScheduleController
{
    public FleetOpsInternalMaintenanceScheduleFake $schedule;
    public array $scheduleLookups          = [];
    public array $relationLookups          = [];
    public array $activeScheduleWindowEnds = [];
    public Collection $activeSchedules;
    public ?WorkOrder $createdWorkOrder = null;
    public ?string $sessionUser         = 'user-uuid';

    public function __construct()
    {
        $this->schedule        = fleetopsInternalMaintenanceSchedule();
        $this->activeSchedules = collect();
    }

    protected function findSchedule(string $id): MaintenanceSchedule
    {
        $this->scheduleLookups[] = $id;

        return $this->schedule;
    }

    protected function findScheduleWithRelations(string $id, array $relations): MaintenanceSchedule
    {
        $this->relationLookups[] = [$id, $relations];

        return $this->schedule;
    }

    protected function activeCalendarSchedules(Carbon $windowEnd): iterable
    {
        $this->activeScheduleWindowEnds[] = $windowEnd->toDateTimeString();

        return $this->activeSchedules;
    }

    protected function createWorkOrderFromSchedule(MaintenanceSchedule $schedule): WorkOrder
    {
        $workOrder = new FleetOpsInternalMaintenanceScheduleWorkOrderFake();
        $workOrder->setRawAttributes([
            'uuid'            => 'work-order-uuid',
            'company_uuid'    => $schedule->company_uuid,
            'schedule_uuid'   => $schedule->uuid,
            'subject'         => $schedule->name,
            'category'        => 'preventive_maintenance',
            'status'          => 'open',
            'priority'        => $schedule->default_priority ?? 'normal',
            'target_type'     => $schedule->subject_type,
            'target_uuid'     => $schedule->subject_uuid,
            'assignee_type'   => $schedule->default_assignee_type,
            'assignee_uuid'   => $schedule->default_assignee_uuid,
            'instructions'    => $schedule->instructions,
            'due_at'          => $schedule->next_due_date,
            'created_by_uuid' => $this->sessionUserUuid(),
        ], true);

        return $this->createdWorkOrder = $workOrder;
    }

    protected function sessionUserUuid(): ?string
    {
        return $this->sessionUser;
    }

    protected function icalResponse(string $content, array $headers): Response
    {
        return new Response($content, 200, $headers);
    }
}

class FleetOpsInternalMaintenanceScheduleFake extends MaintenanceSchedule
{
    public int $pauseCalls  = 0;
    public int $resumeCalls = 0;
    public int $freshCalls  = 0;

    public function pause(): bool
    {
        $this->pauseCalls++;
        $this->forceFill(['status' => 'paused']);

        return true;
    }

    public function resume(): bool
    {
        $this->resumeCalls++;
        $this->forceFill(['status' => 'active']);

        return true;
    }

    public function fresh($with = [])
    {
        $this->freshCalls++;

        return $this;
    }
}

class FleetOpsInternalMaintenanceScheduleWorkOrderFake extends WorkOrder
{
    protected $appends = [];
    protected $with    = [];
    protected $casts   = [];
}

function fleetopsInternalMaintenanceSchedule(array $attributes = [], ?object $subject = null, ?object $assignee = null): FleetOpsInternalMaintenanceScheduleFake
{
    $schedule = new FleetOpsInternalMaintenanceScheduleFake();
    $schedule->setRawAttributes(array_merge([
        'uuid'                  => 'schedule-uuid',
        'public_id'             => 'schedule_public',
        'company_uuid'          => 'company-uuid',
        'name'                  => 'Quarterly inspection',
        'status'                => 'active',
        'type'                  => 'inspection',
        'default_priority'      => 'high',
        'subject_type'          => Vehicle::class,
        'subject_uuid'          => 'vehicle-uuid',
        'default_assignee_type' => 'vendor',
        'default_assignee_uuid' => 'vendor-uuid',
        'instructions'          => 'Check brakes and tires.',
        'next_due_date'         => Carbon::parse('2026-08-15'),
        'interval_value'        => null,
        'interval_unit'         => null,
    ], $attributes), true);
    $schedule->setRelation('subject', $subject ?? (object) ['name' => 'Truck 15']);
    $schedule->setRelation('defaultAssignee', $assignee);

    return $schedule;
}

test('internal maintenance schedule controller pauses and resumes schedules by id', function () {
    $controller = new FleetOpsInternalMaintenanceScheduleControllerProbe();

    $pause  = $controller->pause('schedule_public');
    $resume = $controller->resume('schedule_public');

    expect($pause->getData(true))->toMatchArray([
        'status'  => 'ok',
        'message' => 'Maintenance schedule paused.',
    ])
        ->and($resume->getData(true))->toMatchArray([
            'status'  => 'ok',
            'message' => 'Maintenance schedule resumed.',
        ])
        ->and($controller->scheduleLookups)->toBe(['schedule_public', 'schedule_public'])
        ->and($controller->schedule->pauseCalls)->toBe(1)
        ->and($controller->schedule->resumeCalls)->toBe(1)
        ->and($controller->schedule->freshCalls)->toBe(2);
});

test('internal maintenance schedule controller creates work orders from schedules', function () {
    $controller              = new FleetOpsInternalMaintenanceScheduleControllerProbe();
    $controller->sessionUser = 'creator-uuid';

    $response = $controller->trigger('schedule-uuid', new Request());

    expect($response->getData(true))->toMatchArray([
        'status'  => 'ok',
        'message' => 'Work order created from schedule.',
    ])
        ->and($controller->scheduleLookups)->toBe(['schedule-uuid'])
        ->and($controller->createdWorkOrder)->toBeInstanceOf(WorkOrder::class)
        ->and($controller->createdWorkOrder->getAttributes())->toMatchArray([
            'company_uuid'    => 'company-uuid',
            'schedule_uuid'   => 'schedule-uuid',
            'subject'         => 'Quarterly inspection',
            'category'        => 'preventive_maintenance',
            'status'          => 'open',
            'priority'        => 'high',
            'target_type'     => Vehicle::class,
            'target_uuid'     => 'vehicle-uuid',
            'assignee_type'   => 'vendor',
            'assignee_uuid'   => 'vendor-uuid',
            'instructions'    => 'Check brakes and tires.',
            'created_by_uuid' => 'creator-uuid',
        ]);
});

test('internal maintenance schedule controller calendar feed parses request windows', function () {
    $controller                  = new FleetOpsInternalMaintenanceScheduleControllerProbe();
    $controller->activeSchedules = collect([
        fleetopsInternalMaintenanceSchedule([
            'public_id'        => 'schedule_one',
            'name'             => 'Weekly inspection',
            'default_priority' => 'normal',
            'next_due_date'    => Carbon::parse('2026-08-10'),
            'interval_value'   => 7,
            'interval_unit'    => 'days',
        ], (object) ['display_name' => 'Van 4'], (object) ['name' => 'Ops Vendor']),
    ]);

    $response = $controller->calendarFeed(new Request([
        'start' => '2026-08-09',
        'end'   => '2026-08-24',
    ]));

    expect($controller->activeScheduleWindowEnds)->toBe(['2026-08-24 23:59:59'])
        ->and($response->getData(true)['events'])->toHaveCount(3)
        ->and($response->getData(true)['events'][0])->toMatchArray([
            'id'              => 'schedule_one',
            'title'           => 'Weekly inspection — Van 4',
            'start'           => '2026-08-10',
            'end'             => '2026-08-10',
            'occurrence_date' => '2026-08-10',
            'assignee_name'   => 'Ops Vendor',
            'color'           => '#3b82f6',
        ])
        ->and($response->getData(true)['events'][1])->toMatchArray([
            'id'              => 'schedule_one',
            'start'           => '2026-08-17',
            'end'             => '2026-08-17',
            'occurrence_date' => '2026-08-17',
        ])
        ->and($response->getData(true)['events'][2])->toMatchArray([
            'id'              => 'schedule_one',
            'start'           => '2026-08-24',
            'end'             => '2026-08-24',
            'occurrence_date' => '2026-08-24',
        ]);
});

test('internal maintenance schedule controller returns ical downloads with recurrence metadata', function () {
    $controller           = new FleetOpsInternalMaintenanceScheduleControllerProbe();
    $controller->schedule = fleetopsInternalMaintenanceSchedule([
        'uuid'             => 'schedule-ical-uuid',
        'public_id'        => 'schedule_ical',
        'name'             => 'Monthly PM',
        'type'             => 'preventive_maintenance',
        'default_priority' => 'critical',
        'next_due_date'    => Carbon::parse('2026-09-01'),
        'interval_value'   => 1,
        'interval_unit'    => 'months',
    ], (object) ['public_id' => 'asset_public']);

    $response = $controller->ical('schedule_ical');
    $content  = $response->getContent();

    expect($controller->relationLookups)->toBe([
        ['schedule_ical', ['subject', 'defaultAssignee']],
    ])
        ->and($response->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="maintenance-schedule_ical.ics"')
        ->and($content)->toContain('BEGIN:VCALENDAR')
        ->and($content)->toContain('SUMMARY:Monthly PM — asset_public')
        ->and($content)->toContain('UID:schedule-ical-uuid@fleetbase.io')
        ->and($content)->toContain('RRULE:FREQ=MONTHLY;INTERVAL=1')
        ->and($content)->toContain('Priority: Critical');
});
