<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Observers\WorkOrderObserver;

class FleetOpsWorkOrderObserverProbe extends WorkOrderObserver
{
    public bool $maintenanceExists        = false;
    public ?MaintenanceSchedule $schedule = null;
    public array $createdMaintenance      = [];
    public array $completedEvents         = [];

    protected function hasMaintenanceRecord(WorkOrder $workOrder): bool
    {
        return $this->maintenanceExists;
    }

    protected function createMaintenance(array $attributes): Maintenance
    {
        $this->createdMaintenance[] = $attributes;

        return new Maintenance();
    }

    protected function findSchedule(string $uuid): ?MaintenanceSchedule
    {
        return $this->schedule && $this->schedule->uuid === $uuid ? $this->schedule : null;
    }

    protected function dispatchCompletedEvent(WorkOrder $workOrder): void
    {
        $this->completedEvents[] = $workOrder;
    }
}

class FleetOpsWorkOrderObserverWorkOrderFake extends WorkOrder
{
    public bool $statusWasChanged = true;

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'status' ? $this->statusWasChanged : false;
    }
}

class FleetOpsWorkOrderObserverScheduleFake extends MaintenanceSchedule
{
    public array $resets = [];

    public function resetAfterCompletion(?int $completedOdometer = null, ?int $completedEngineHours = null, ?Carbon $completedAt = null): bool
    {
        $this->resets[] = [$completedOdometer, $completedEngineHours, $completedAt];

        return true;
    }
}

test('work order observer creates maintenance resets schedule and dispatches completed event on close', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

    $schedule = new FleetOpsWorkOrderObserverScheduleFake();
    $schedule->setRawAttributes(['uuid' => 'schedule-uuid']);

    $workOrder = new FleetOpsWorkOrderObserverWorkOrderFake();
    $workOrder->setRawAttributes([
        'uuid'            => 'work-order-uuid',
        'company_uuid'    => 'company-uuid',
        'status'          => 'closed',
        'target_type'     => 'fleet-ops:vehicle',
        'target_uuid'     => 'vehicle-uuid',
        'priority'        => 'high',
        'opened_at'       => Carbon::parse('2026-03-01 09:00:00'),
        'closed_at'       => Carbon::parse('2026-03-05 10:30:00'),
        'assignee_type'   => 'fleet-ops:contact',
        'assignee_uuid'   => 'assignee-uuid',
        'subject'         => 'Replace brakes',
        'created_by_uuid' => 'creator-uuid',
        'schedule_uuid'   => 'schedule-uuid',
        'meta'            => [
            'completion_data' => [
                'notes'        => 'Completed cleanly',
                'odometer'     => '12000',
                'engine_hours' => '550',
                'labor_cost'   => 1000,
                'parts_cost'   => 2500,
                'tax'          => 300,
                'total_cost'   => 3800,
                'currency'     => 'USD',
                'line_items'   => [['label' => 'Brake pads']],
            ],
        ],
    ]);

    $observer           = new FleetOpsWorkOrderObserverProbe();
    $observer->schedule = $schedule;

    $observer->updated($workOrder);

    expect($observer->createdMaintenance)->toHaveCount(1)
        ->and($observer->createdMaintenance[0])->toMatchArray([
            'company_uuid'      => 'company-uuid',
            'work_order_uuid'   => 'work-order-uuid',
            'maintainable_type' => 'fleet-ops:vehicle',
            'maintainable_uuid' => 'vehicle-uuid',
            'type'              => 'scheduled',
            'status'            => 'done',
            'priority'          => 'high',
            'performed_by_type' => 'fleet-ops:contact',
            'performed_by_uuid' => 'assignee-uuid',
            'summary'           => 'Replace brakes',
            'notes'             => 'Completed cleanly',
            'odometer'          => '12000',
            'engine_hours'      => '550',
            'total_cost'        => 3800,
            'created_by_uuid'   => 'creator-uuid',
        ])
        ->and($observer->createdMaintenance[0]['completed_at']->toDateTimeString())->toBe('2026-03-05 10:30:00')
        ->and($schedule->resets)->toHaveCount(1)
        ->and($schedule->resets[0][0])->toBe(12000)
        ->and($schedule->resets[0][1])->toBe(550)
        ->and($schedule->resets[0][2]->toDateTimeString())->toBe('2026-03-05 10:30:00')
        ->and($observer->completedEvents)->toBe([$workOrder]);

    Carbon::setTestNow();
});

test('work order observer skips non closing duplicate and missing schedule branches', function () {
    $observer = new FleetOpsWorkOrderObserverProbe();

    $unchanged = new FleetOpsWorkOrderObserverWorkOrderFake();
    $unchanged->setRawAttributes(['status' => 'closed']);
    $unchanged->statusWasChanged = false;
    $observer->updated($unchanged);

    $open = new FleetOpsWorkOrderObserverWorkOrderFake();
    $open->setRawAttributes(['status' => 'open']);
    $observer->updated($open);

    expect($observer->createdMaintenance)->toBe([])
        ->and($observer->completedEvents)->toBe([]);

    $duplicate = new FleetOpsWorkOrderObserverWorkOrderFake();
    $duplicate->setRawAttributes([
        'uuid'          => 'duplicate-work-order',
        'status'        => 'closed',
        'schedule_uuid' => 'missing-schedule',
    ]);
    $observer->maintenanceExists = true;
    $observer->updated($duplicate);

    expect($observer->createdMaintenance)->toBe([])
        ->and($observer->completedEvents)->toBe([$duplicate]);
});
