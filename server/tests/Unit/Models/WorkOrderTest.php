<?php

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return \FleetOpsWorkOrderActivityFake::start($logName); }');
}

if (!function_exists('Fleetbase\FleetOps\Models\auth')) {
    eval('namespace Fleetbase\FleetOps\Models; function auth() { return new class { public function id() { return "work-order-user"; } }; }');
}

use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;

class FleetOpsWorkOrderActivityFake
{
    public static array $entries = [];

    public static function start(?string $logName = null): self
    {
        static::$entries[] = ['log_name' => $logName];

        return new self(count(static::$entries) - 1);
    }

    public function __construct(private int $index)
    {
    }

    public function performedOn($subject): self
    {
        static::$entries[$this->index]['subject'] = $subject;

        return $this;
    }

    public function withProperties(array $properties): self
    {
        static::$entries[$this->index]['properties'] = $properties;

        return $this;
    }

    public function log(string $message): bool
    {
        static::$entries[$this->index]['message'] = $message;

        return true;
    }
}

class FleetOpsWorkOrderDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

class FleetOpsWorkOrderUpdatingFake extends WorkOrder
{
    public array $updates = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct();

        if ($attributes) {
            $this->setRawAttributes($attributes, true);
        }
    }

    public function getAttribute($key)
    {
        if (in_array($key, ['checklist', 'meta'], true)) {
            return $this->attributes[$key] ?? null;
        }

        if (in_array($key, ['opened_at', 'due_at', 'closed_at'], true)) {
            return isset($this->attributes[$key]) ? Carbon::parse($this->attributes[$key]) : null;
        }

        return parent::getAttribute($key);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

class FleetOpsWorkOrderAssigneeFake extends Vendor
{
    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }
}

function fleetopsWorkOrderUseConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsWorkOrderDatabaseProbe($connection));
}

beforeEach(function () {
    fleetopsWorkOrderUseConnection();
    FleetOpsWorkOrderActivityFake::$entries = [];
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('work order relationship contracts and activity options are stable', function () {
    $workOrder = new WorkOrder();

    expect($workOrder->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($workOrder->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($workOrder->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($workOrder->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($workOrder->updatedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($workOrder->target())->toBeInstanceOf(MorphTo::class)
        ->and($workOrder->assignee())->toBeInstanceOf(MorphTo::class)
        ->and($workOrder->maintenances())->toBeInstanceOf(HasMany::class)
        ->and($workOrder->maintenances()->getRelated())->toBeInstanceOf(Maintenance::class)
        ->and($workOrder->maintenances()->getForeignKeyName())->toBe('work_order_uuid')
        ->and($workOrder->documents())->toBeInstanceOf(HasMany::class)
        ->and($workOrder->documents()->getRelated())->toBeInstanceOf(File::class)
        ->and($workOrder->documents()->getForeignKeyName())->toBe('subject_uuid');
});

test('work order lifecycle helpers update state and record activity', function () {
    $assignee = new FleetOpsWorkOrderAssigneeFake();
    $assignee->forceFill(['uuid' => 'vendor-uuid', 'name' => 'Vendor Crew']);

    $workOrder = new FleetOpsWorkOrderUpdatingFake([
        'uuid'      => 'work-order-uuid',
        'status'    => 'open',
        'opened_at' => null,
        'meta'      => ['existing' => true],
    ]);

    expect($workOrder->assignTo($assignee))->toBeTrue()
        ->and($workOrder->updates[0])->toBe([
            'assignee_type' => FleetOpsWorkOrderAssigneeFake::class,
            'assignee_uuid' => 'vendor-uuid',
        ])
        ->and($workOrder->start())->toBeTrue()
        ->and($workOrder->updates[1]['status'])->toBe('in_progress')
        ->and($workOrder->updates[1]['opened_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($workOrder->complete(['notes' => 'Done']))->toBeTrue()
        ->and($workOrder->updates[2]['status'])->toBe('closed')
        ->and($workOrder->updates[2]['closed_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($workOrder->updates[2]['meta'])->toBe([
            'existing'         => true,
            'completion_data'  => ['notes' => 'Done'],
        ])
        ->and(FleetOpsWorkOrderActivityFake::$entries[0]['log_name'])->toBe('work_order_assigned')
        ->and(FleetOpsWorkOrderActivityFake::$entries[0]['properties'])->toBe([
            'assigned_to_type' => FleetOpsWorkOrderAssigneeFake::class,
            'assigned_to_uuid' => 'vendor-uuid',
            'assigned_to_name' => 'Vendor Crew',
        ])
        ->and(FleetOpsWorkOrderActivityFake::$entries[0]['message'])->toBe('Work order assigned')
        ->and(FleetOpsWorkOrderActivityFake::$entries[1]['log_name'])->toBe('work_order_started')
        ->and(FleetOpsWorkOrderActivityFake::$entries[1]['message'])->toBe('Work order started')
        ->and(FleetOpsWorkOrderActivityFake::$entries[2]['log_name'])->toBe('work_order_completed')
        ->and(FleetOpsWorkOrderActivityFake::$entries[2]['properties'])->toBe(['notes' => 'Done'])
        ->and(FleetOpsWorkOrderActivityFake::$entries[2]['message'])->toBe('Work order completed');
});

test('work order lifecycle guards reject invalid transitions and cancel open work', function () {
    $closed = new FleetOpsWorkOrderUpdatingFake(['status' => 'closed']);

    expect($closed->start())->toBeFalse()
        ->and($closed->complete())->toBeFalse()
        ->and($closed->cancel('duplicate'))->toBeFalse()
        ->and($closed->updates)->toBe([]);

    $workOrder = new FleetOpsWorkOrderUpdatingFake([
        'status' => 'in_progress',
        'meta'   => ['existing' => true],
    ]);

    expect($workOrder->cancel('No longer needed'))->toBeTrue()
        ->and($workOrder->updates[0]['status'])->toBe('canceled')
        ->and($workOrder->updates[0]['closed_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($workOrder->updates[0]['meta'])->toBe([
            'existing'             => true,
            'cancellation_reason'  => 'No longer needed',
        ])
        ->and(FleetOpsWorkOrderActivityFake::$entries[0]['log_name'])->toBe('work_order_canceled')
        ->and(FleetOpsWorkOrderActivityFake::$entries[0]['properties'])->toBe(['reason' => 'No longer needed'])
        ->and(FleetOpsWorkOrderActivityFake::$entries[0]['message'])->toBe('Work order canceled');
});

test('work order checklist helpers use authenticated fallback and schedule helpers handle nulls', function () {
    $workOrder = new FleetOpsWorkOrderUpdatingFake([
        'status'    => 'open',
        'checklist' => [
            ['label' => 'Inspect liftgate', 'completed' => false],
        ],
    ]);

    expect($workOrder->completeChecklistItem(0))->toBeTrue()
        ->and($workOrder->updates[0]['checklist'][0]['completed'])->toBeTrue()
        ->and($workOrder->updates[0]['checklist'][0]['completed_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($workOrder->updates[0]['checklist'][0]['completed_by'])->toBe('work-order-user')
        ->and($workOrder->getActualDuration())->toBeNull()
        ->and($workOrder->isOnSchedule())->toBeNull();
});

test('work order timing and priority helpers cover overdue and open schedule branches', function () {
    $overdue = new FleetOpsWorkOrderUpdatingFake([
        'status'   => 'open',
        'due_at'   => '2026-07-26 12:00:00',
        'priority' => 'critical',
    ]);

    $future = new FleetOpsWorkOrderUpdatingFake([
        'status'   => 'in_progress',
        'due_at'   => '2026-07-28 12:00:00',
        'priority' => 'medium',
    ]);

    $closedLate = new FleetOpsWorkOrderUpdatingFake([
        'status'    => 'closed',
        'opened_at' => '2026-07-25 12:00:00',
        'closed_at' => '2026-07-27 12:00:00',
        'due_at'    => '2026-07-26 12:00:00',
        'priority'  => 'low',
    ]);

    expect($overdue->is_overdue)->toBeTrue()
        ->and($overdue->days_until_due)->toBe(-1)
        ->and($overdue->isOnSchedule())->toBeFalse()
        ->and($overdue->getPriorityLevel())->toBe(5)
        ->and($future->is_overdue)->toBeFalse()
        ->and($future->days_until_due)->toBe(1)
        ->and($future->isOnSchedule())->toBeTrue()
        ->and($future->getPriorityLevel())->toBe(3)
        ->and($closedLate->getActualDuration())->toBe(48.0)
        ->and($closedLate->isOnSchedule())->toBeFalse()
        ->and($closedLate->getPriorityLevel())->toBe(2)
        ->and((new FleetOpsWorkOrderUpdatingFake(['priority' => 'unknown']))->getPriorityLevel())->toBe(1);
});
