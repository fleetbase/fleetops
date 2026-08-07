<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Traits\Maintainable;
use Illuminate\Support\Collection;

class FleetOpsMaintainableRelationProbe
{
    public array $calls = [];

    public function __construct(private Collection $records, private mixed $firstRecord = null, private bool $exists = false)
    {
    }

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereNotNull(...$arguments): self
    {
        $this->calls[] = ['whereNotNull', $arguments];

        return $this;
    }

    public function orderBy(...$arguments): self
    {
        $this->calls[] = ['orderBy', $arguments];

        return $this;
    }

    public function get(): Collection
    {
        return $this->records;
    }

    public function first(): mixed
    {
        return $this->firstRecord;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function count(): int
    {
        return $this->records->count();
    }

    public function sum(string $key): mixed
    {
        return $this->records->sum($key);
    }
}

class FleetOpsMaintainableProbe
{
    use Maintainable {
        checkIntervalsSinceDate as public exposeCheckIntervalsSinceDate;
        checkMaintenanceIntervals as public exposeCheckMaintenanceIntervals;
    }

    public ?object $last_maintenance = null;
    public array $specs              = [];
    public array $meta               = [];
    public ?Carbon $created_at       = null;
    public ?Carbon $purchased_at     = null;
    public ?int $odometer            = null;
    public ?int $engine_hours        = null;

    public FleetOpsMaintainableRelationProbe $overdueRelation;
    public FleetOpsMaintainableRelationProbe $completedRelation;
    public FleetOpsMaintainableRelationProbe $scheduledRelation;
    public FleetOpsMaintainableRelationProbe $maintenanceRelation;

    public function __construct()
    {
        $this->created_at          = now()->subDays(20);
        $this->overdueRelation     = new FleetOpsMaintainableRelationProbe(collect());
        $this->completedRelation   = new FleetOpsMaintainableRelationProbe(collect());
        $this->scheduledRelation   = new FleetOpsMaintainableRelationProbe(collect());
        $this->maintenanceRelation = new FleetOpsMaintainableRelationProbe(collect());
    }

    public function overdueMaintenances(): FleetOpsMaintainableRelationProbe
    {
        return $this->overdueRelation;
    }

    public function completedMaintenances(): FleetOpsMaintainableRelationProbe
    {
        return $this->completedRelation;
    }

    public function scheduledMaintenances(): FleetOpsMaintainableRelationProbe
    {
        return $this->scheduledRelation;
    }

    public function maintenances(): FleetOpsMaintainableRelationProbe
    {
        return $this->maintenanceRelation;
    }
}

class FleetOpsCompletedMaintenanceProbe
{
    public string $status = 'completed';
    public Carbon $started_at;
    public Carbon $completed_at;

    public function __construct(
        public float $total_cost,
        public int $duration_hours,
        public string $type,
        private bool $onTime,
    ) {
        $this->started_at   = Carbon::parse('2026-07-01 08:00:00');
        $this->completed_at = $this->started_at->copy()->addHours($duration_hours);
    }

    public function wasCompletedOnTime(): bool
    {
        return $this->onTime;
    }
}

test('maintainable interval checks use date odometer and engine hour thresholds', function () {
    $probe        = new FleetOpsMaintainableProbe();
    $probe->specs = [
        'maintenance_interval_days'  => 10,
        'maintenance_interval_miles' => 500,
        'maintenance_interval_hours' => 25,
    ];
    $probe->last_maintenance = (object) [
        'completed_at' => now()->subDays(3),
        'odometer'     => 1200,
        'engine_hours' => 40,
    ];
    $probe->odometer     = 1800;
    $probe->engine_hours = 70;

    expect($probe->exposeCheckIntervalsSinceDate(now()->subDays(11)))->toBeTrue()
        ->and($probe->exposeCheckIntervalsSinceDate(now()->subDays(1)))->toBeTrue()
        ->and($probe->exposeCheckMaintenanceIntervals())->toBeTrue();

    $probe->specs            = ['maintenance_interval_days' => 30];
    $probe->last_maintenance = null;
    $probe->purchased_at     = now()->subDays(31);

    expect($probe->exposeCheckMaintenanceIntervals())->toBeTrue();

    $probe->specs            = [];
    $probe->meta             = ['maintenance_interval_days' => 7];
    $probe->created_at       = now()->subDays(8);
    $probe->purchased_at     = null;
    $probe->last_maintenance = null;

    expect($probe->exposeCheckMaintenanceIntervals())->toBeTrue();
});

test('maintainable status helpers report overdue and aggregate maintenance metrics', function () {
    $probe                  = new FleetOpsMaintainableProbe();
    $probe->overdueRelation = new FleetOpsMaintainableRelationProbe(collect(), null, true);

    expect($probe->needsMaintenance())->toBeTrue();

    $completed = collect([
        new FleetOpsCompletedMaintenanceProbe(120.0, 2, 'inspection', true),
        new FleetOpsCompletedMaintenanceProbe(80.0, 3, 'repair', false),
    ]);
    $probe->completedRelation = new FleetOpsMaintainableRelationProbe($completed, $completed->first());

    expect($probe->getMaintenanceCost(30))->toBe(200.0)
        ->and($probe->getMaintenanceFrequency(30))->toBe((2 / 30) * 365)
        ->and($probe->getAverageMaintenanceDuration(30))->toBe(2.5)
        ->and($probe->getMaintenanceEfficiency(30))->toBe(50.0);
});

test('maintainable schedules and history summaries project maintenance state', function () {
    $probe        = new FleetOpsMaintainableProbe();
    $probe->specs = [
        'maintenance_intervals' => [
            'inspection' => [
                'days'        => 30,
                'priority'    => 'high',
                'description' => 'Inspect brakes',
            ],
        ],
    ];
    $lastCompleted            = (object) ['completed_at' => now()->subDays(10)];
    $probe->completedRelation = new FleetOpsMaintainableRelationProbe(collect([
        new FleetOpsCompletedMaintenanceProbe(100, 2, 'inspection', true),
        new FleetOpsCompletedMaintenanceProbe(50, 1, 'inspection', false),
    ]), $lastCompleted);
    $probe->scheduledRelation = new FleetOpsMaintainableRelationProbe(collect([
        (object) [
            'status'       => 'scheduled',
            'scheduled_at' => now()->addDays(5),
        ],
    ]));
    $probe->maintenanceRelation = new FleetOpsMaintainableRelationProbe(collect([
        new FleetOpsCompletedMaintenanceProbe(100, 2, 'inspection', true),
        (object) [
            'status'       => 'scheduled',
            'scheduled_at' => now()->subDay(),
        ],
    ]));

    $schedule = $probe->createPreventiveMaintenanceSchedule([
        'oil_change' => ['days' => 45],
    ]);

    expect($probe->getUpcomingMaintenance(14))->toHaveCount(1)
        ->and($schedule)->toHaveCount(2)
        ->and($schedule[0])->toMatchArray([
            'type'          => 'inspection',
            'interval_days' => 30,
            'priority'      => 'high',
            'description'   => 'Inspect brakes',
        ])
        ->and($schedule[1])->toMatchArray([
            'type'          => 'oil_change',
            'interval_days' => 45,
            'priority'      => 'medium',
            'description'   => 'Scheduled oil_change maintenance',
        ])
        ->and($probe->getMaintenanceHistorySummary(60))->toMatchArray([
            'total_maintenances'     => 2,
            'completed_count'        => 1,
            'scheduled_count'        => 1,
            'overdue_count'          => 1,
            'total_cost'             => 100,
            'average_cost'           => 100,
            'total_downtime_hours'   => 2,
            'average_duration_hours' => 2,
            'most_common_type'       => 'inspection',
        ]);
});
