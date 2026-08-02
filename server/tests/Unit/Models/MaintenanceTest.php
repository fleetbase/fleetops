<?php

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-maintenance" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return new class($logName) { public function __construct(public $logName) {} public function performedOn($subject) { return $this; } public function withProperties(array $properties) { return $this; } public function log(string $message) { return true; } }; }');
}

use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Models\Model as FleetbaseModel;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;

class FleetOpsMaintenanceModelDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(string $value)
    {
        return $this->connection->raw($value);
    }
}

class FleetOpsMaintenanceQueryFake
{
    public array $calls = [];

    public function where(...$arguments): static
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }
}

class FleetOpsMaintenanceUpdatingFake extends Maintenance
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsMaintenanceMaintainableFake extends FleetbaseModel
{
    public array $odometers   = [];
    public array $engineHours = [];

    public function updateOdometer($odometer, string $source): void
    {
        $this->odometers[] = [$odometer, $source];
    }

    public function updateEngineHours($engineHours, string $source): void
    {
        $this->engineHours[] = [$engineHours, $source];
    }
}

function fleetopsMaintenanceModelUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsMaintenanceModelDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    return $connection;
}

beforeEach(function () {
    fleetopsMaintenanceModelUseInMemoryConnection();
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('maintenance relationship contracts and activity options are stable', function () {
    $maintenance = new Maintenance();

    expect($maintenance->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($maintenance->workOrder())->toBeInstanceOf(BelongsTo::class)
        ->and($maintenance->workOrder()->getRelated())->toBeInstanceOf(WorkOrder::class)
        ->and($maintenance->workOrder()->getForeignKeyName())->toBe('work_order_uuid')
        ->and($maintenance->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($maintenance->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($maintenance->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($maintenance->updatedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($maintenance->maintainable())->toBeInstanceOf(MorphTo::class)
        ->and($maintenance->maintainable()->getMorphType())->toBe('maintainable_type')
        ->and($maintenance->maintainable()->getForeignKeyName())->toBe('maintainable_uuid')
        ->and($maintenance->performedBy())->toBeInstanceOf(MorphTo::class)
        ->and($maintenance->performedBy()->getMorphType())->toBe('performed_by_type')
        ->and($maintenance->performedBy()->getForeignKeyName())->toBe('performed_by_uuid');
});

test('maintenance scopes write expected query constraints', function () {
    $maintenance = new Maintenance();
    $query       = new FleetOpsMaintenanceQueryFake();

    expect($maintenance->scopeByStatus($query, 'completed'))->toBe($query)
        ->and($maintenance->scopeScheduled($query))->toBe($query)
        ->and($maintenance->scopeOverdue($query))->toBe($query)
        ->and($maintenance->scopeByType($query, 'inspection'))->toBe($query)
        ->and($maintenance->scopeByPriority($query, 'high'))->toBe($query)
        ->and($query->calls[0])->toBe(['where', ['status', 'completed']])
        ->and($query->calls[1])->toBe(['where', ['status', 'scheduled']])
        ->and($query->calls[2][0])->toBe('where')
        ->and($query->calls[2][1][0])->toBe('scheduled_at')
        ->and($query->calls[2][1][1])->toBe('<')
        ->and($query->calls[3])->toBe(['where', ['status', '!=', 'completed']])
        ->and($query->calls[4])->toBe(['where', ['type', 'inspection']])
        ->and($query->calls[5])->toBe(['where', ['priority', 'high']]);
});

test('maintenance accessors handle empty and related states', function () {
    $namedMaintainable = new FleetOpsMaintenanceMaintainableFake();
    $namedMaintainable->setRawAttributes(['name' => 'Lift gate'], true);

    $displayMaintainable = new FleetOpsMaintenanceMaintainableFake();
    $displayMaintainable->setRawAttributes(['display_name' => 'Trailer unit'], true);

    $performer = new FleetOpsMaintenanceMaintainableFake();
    $performer->setRawAttributes(['display_name' => 'Contractor Team'], true);

    $maintenance = new Maintenance([
        'status'       => 'scheduled',
        'scheduled_at' => Carbon::parse('2026-07-28 12:00:00'),
        'started_at'   => Carbon::parse('2026-07-27 08:00:00'),
        'completed_at' => Carbon::parse('2026-07-27 11:00:00'),
        'labor_cost'   => 2000,
        'parts_cost'   => 1500,
        'tax'          => 250,
        'total_cost'   => 3750,
        'meta'         => ['estimated_duration_hours' => 2],
    ]);
    $maintenance->setRelation('maintainable', $namedMaintainable);
    $maintenance->setRelation('performedBy', $performer);
    $maintenance->setRelation('workOrder', (object) ['subject' => 'Hydraulic inspection']);

    $displayMaintenance = new Maintenance();
    $displayMaintenance->setRelation('maintainable', $displayMaintainable);

    $empty = new Maintenance();

    expect($maintenance->maintainable_name)->toBe('Lift gate')
        ->and($displayMaintenance->maintainable_name)->toBe('Trailer unit')
        ->and($empty->maintainable_name)->toBeNull()
        ->and($maintenance->performed_by_name)->toBe('Contractor Team')
        ->and($empty->performed_by_name)->toBeNull()
        ->and($maintenance->work_order_subject)->toBe('Hydraulic inspection')
        ->and($empty->work_order_subject)->toBeNull()
        ->and($maintenance->duration_hours)->toBe(3.0)
        ->and($empty->duration_hours)->toBeNull()
        ->and($maintenance->is_overdue)->toBeFalse()
        ->and($maintenance->days_until_due)->toBe(1)
        ->and($maintenance->cost_breakdown)->toMatchArray([
            'subtotal'   => 3500,
            'total_cost' => 3750,
        ])
        ->and($maintenance->getEfficiencyRating())->toBe(66.66666666666666)
        ->and($maintenance->wasCompletedOnTime())->toBeTrue()
        ->and($maintenance->getCostPerHour())->toBe(1250.0)
        ->and($empty->getEfficiencyRating())->toBeNull()
        ->and($empty->wasCompletedOnTime())->toBeNull()
        ->and($empty->getCostPerHour())->toBeNull();
});

test('maintenance lifecycle success branches update state and maintainable readings', function () {
    $performer = new FleetOpsMaintenanceMaintainableFake();
    $performer->setRawAttributes(['uuid' => 'user-performer'], true);

    $maintenance = new FleetOpsMaintenanceUpdatingFake([
        'status'     => 'scheduled',
        'labor_cost' => 1000,
        'parts_cost' => 2000,
        'tax'        => 100,
    ]);

    expect($maintenance->start($performer))->toBeTrue()
        ->and($maintenance->updates[0]['status'])->toBe('in_progress')
        ->and($maintenance->updates[0]['started_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($maintenance->updates[0]['performed_by_type'])->toBe(FleetOpsMaintenanceMaintainableFake::class)
        ->and($maintenance->updates[0]['performed_by_uuid'])->toBe('user-performer');

    $maintainable = new FleetOpsMaintenanceMaintainableFake();
    $maintenance->setRelation('maintainable', $maintainable);

    expect($maintenance->complete([
        'labor_cost'   => 1200,
        'parts_cost'   => 2500,
        'tax'          => 300,
        'notes'        => 'Completed with calibration',
        'line_items'   => [['name' => 'Filter']],
        'attachments'  => [['file_uuid' => 'file-uuid']],
        'odometer'     => 12345,
        'engine_hours' => 88,
    ]))->toBeTrue()
        ->and($maintenance->updates[1]['status'])->toBe('completed')
        ->and($maintenance->updates[1]['completed_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($maintenance->updates[1]['total_cost'])->toBe(4000)
        ->and($maintenance->updates[1]['notes'])->toBe('Completed with calibration')
        ->and($maintainable->odometers)->toBe([[12345, 'maintenance']])
        ->and($maintainable->engineHours)->toBe([[88, 'maintenance']]);
});

test('maintenance cancellation line items attachments and mutators are stable', function () {
    $maintenance = new FleetOpsMaintenanceUpdatingFake([
        'status'      => 'scheduled',
        'line_items'  => [['name' => 'Existing']],
        'attachments' => [],
        'meta'        => ['source' => 'import'],
    ]);

    expect($maintenance->cancel('Duplicate request'))->toBeTrue()
        ->and($maintenance->updates[0])->toMatchArray([
            'status' => 'canceled',
            'meta'   => [
                'source'              => 'import',
                'cancellation_reason' => 'Duplicate request',
            ],
        ])
        ->and($maintenance->addLineItem(['name' => 'Filter', 'unit_cost' => 1200]))->toBeTrue()
        ->and($maintenance->updates[1]['line_items'][1]['name'])->toBe('Filter')
        ->and($maintenance->updates[1]['line_items'][1]['added_at']->toDateTimeString())->toBe('2026-07-27 12:00:00')
        ->and($maintenance->removeLineItem(0))->toBeTrue()
        ->and($maintenance->updates[2]['line_items'])->toHaveCount(1)
        ->and($maintenance->removeLineItem(10))->toBeFalse()
        ->and($maintenance->addAttachment('file-uuid', 'Invoice'))->toBeTrue()
        ->and($maintenance->updates[3]['attachments'][0])->toMatchArray([
            'file_uuid'   => 'file-uuid',
            'description' => 'Invoice',
        ]);

    $invalidLineItems = new Maintenance();
    $invalidLineItems->setLineItemsAttribute('not-json');

    $normalisedLineItems = new Maintenance();
    $normalisedLineItems->setLineItemsAttribute([
        ['name' => 'Labor', 'unit_cost' => '100.25'],
        ['name' => 'Inspection'],
    ]);

    expect($invalidLineItems->getAttributes()['line_items'])->toBe('[]')
        ->and(json_decode($normalisedLineItems->getAttributes()['line_items'], true))->toBe([
            ['name' => 'Labor', 'unit_cost' => 10025],
            ['name' => 'Inspection'],
        ]);
});
