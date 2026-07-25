<?php

use Fleetbase\FleetOps\Support\Analytics\GeofenceViolations;
use Fleetbase\FleetOps\Support\Analytics\IssuesInsights;
use Fleetbase\FleetOps\Support\Analytics\MaintenanceOverview;
use Fleetbase\FleetOps\Support\Analytics\OrdersByStatus;
use Fleetbase\Models\Company;
use Illuminate\Support\Carbon;

class FleetOpsOrdersByStatusAnalyticsProbe extends OrdersByStatus
{
    public array $periods = [];
    private Illuminate\Support\Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function withRows(Illuminate\Support\Collection $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    protected function statusRows(DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->periods[] = [$start, $end];

        return $this->rows;
    }
}

class FleetOpsGeofenceViolationsAnalyticsProbe extends GeofenceViolations
{
    public array $calls = [];
    public int $today   = 0;
    public int $period  = 0;
    public Illuminate\Support\Collection $dwells;
    public Illuminate\Support\Collection $zones;

    public function __construct()
    {
        $this->dwells = collect();
        $this->zones  = collect();
    }

    protected function violationsToday(string $companyUuid): int
    {
        $this->calls[] = ['today', $companyUuid];

        return $this->today;
    }

    protected function violationsPeriod(string $companyUuid, DateTimeInterface $start, DateTimeInterface $end): int
    {
        $this->calls[] = ['period', $companyUuid, $start, $end];

        return $this->period;
    }

    protected function topDwells(string $companyUuid, DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->calls[] = ['dwells', $companyUuid, $start, $end];

        return $this->dwells;
    }

    protected function byZoneRows(string $companyUuid, DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->calls[] = ['zones', $companyUuid, $start, $end];

        return $this->zones;
    }
}

class FleetOpsMaintenanceOverviewAnalyticsProbe extends MaintenanceOverview
{
    public array $calls         = [];
    public int $overdue         = 0;
    public int $nextSevenDays   = 0;
    public int $inProgress      = 0;
    public int|float $monthCost = 0;
    public int|float $ytdCost   = 0;
    public Illuminate\Support\Collection $upcoming;

    public function __construct()
    {
        $this->upcoming = collect();
    }

    protected function overdueCount(string $companyUuid, Carbon $now): int
    {
        $this->calls[] = ['overdue', $companyUuid, $now->toDateTimeString()];

        return $this->overdue;
    }

    protected function nextSevenDaysCount(string $companyUuid, Carbon $now): int
    {
        $this->calls[] = ['next7d', $companyUuid, $now->toDateTimeString()];

        return $this->nextSevenDays;
    }

    protected function inProgressCount(string $companyUuid): int
    {
        $this->calls[] = ['in_progress', $companyUuid];

        return $this->inProgress;
    }

    protected function costThisMonth(string $companyUuid, string $currency, Carbon $now): int|float
    {
        $this->calls[] = ['month_cost', $companyUuid, $currency, $now->toDateTimeString()];

        return $this->monthCost;
    }

    protected function costYtd(string $companyUuid, string $currency, Carbon $now): int|float
    {
        $this->calls[] = ['ytd_cost', $companyUuid, $currency, $now->toDateTimeString()];

        return $this->ytdCost;
    }

    protected function upcomingMaintenance(string $companyUuid, Carbon $now)
    {
        $this->calls[] = ['upcoming', $companyUuid, $now->toDateTimeString()];

        return $this->upcoming;
    }
}

class FleetOpsIssuesInsightsAnalyticsProbe extends IssuesInsights
{
    public array $calls = [];
    public Illuminate\Support\Collection $categories;
    public Illuminate\Support\Collection $priorities;
    public int $open = 0;
    public Illuminate\Support\Collection $resolved;

    public function __construct()
    {
        $this->categories = collect();
        $this->priorities = collect();
        $this->resolved   = collect();
    }

    protected function byCategory(string $companyUuid, DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->calls[] = ['category', $companyUuid, $start, $end];

        return $this->categories;
    }

    protected function byPriority(string $companyUuid, DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->calls[] = ['priority', $companyUuid, $start, $end];

        return $this->priorities;
    }

    protected function openCount(string $companyUuid): int
    {
        $this->calls[] = ['open', $companyUuid];

        return $this->open;
    }

    protected function resolvedInWindow(string $companyUuid, DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->calls[] = ['resolved', $companyUuid, $start, $end];

        return $this->resolved;
    }
}

function fleetOpsAnalyticsCompany(string $uuid = 'company-uuid', string $currency = 'USD'): Company
{
    $company = new Company();
    $company->forceFill([
        'uuid'     => $uuid,
        'currency' => $currency,
    ]);

    return $company;
}

test('orders by status analytics builds daily stacked datasets with empty buckets', function () {
    $rows = collect([
        (object) ['bucket' => '2026-07-01', 'status' => 'completed', 'total' => '2'],
        (object) ['bucket' => '2026-07-01', 'status' => 'failed', 'total' => '1'],
        (object) ['bucket' => '2026-07-03', 'status' => 'dispatched', 'total' => '4'],
    ]);

    $start     = new DateTimeImmutable('2026-07-01 08:00:00');
    $end       = new DateTimeImmutable('2026-07-03 18:00:00');
    $analytics = FleetOpsOrdersByStatusAnalyticsProbe::forCompany(fleetOpsAnalyticsCompany())
        ->withRows($rows)
        ->between($start, $end);

    $result = $analytics->get();

    expect($analytics->periods)->toBe([[$start, $end]])
        ->and($result['labels'])->toBe(['Jul 1', 'Jul 2', 'Jul 3'])
        ->and($result['datasets'])->toBe([
            [
                'label'           => 'Completed',
                'data'            => [2, 0, 0],
                'backgroundColor' => '#22c55e',
            ],
            [
                'label'           => 'In Progress',
                'data'            => [0, 0, 0],
                'backgroundColor' => '#3485e2',
            ],
            [
                'label'           => 'Dispatched',
                'data'            => [0, 0, 4],
                'backgroundColor' => '#8b5cf6',
            ],
            [
                'label'           => 'Canceled',
                'data'            => [0, 0, 0],
                'backgroundColor' => '#ef4444',
            ],
            [
                'label'           => 'Failed',
                'data'            => [1, 0, 0],
                'backgroundColor' => '#f59e0b',
            ],
        ]);
});

test('geofence violations analytics maps dwell outliers and zone totals', function () {
    $start     = new DateTimeImmutable('2026-07-10 00:00:00');
    $end       = new DateTimeImmutable('2026-07-12 23:59:59');
    $analytics = FleetOpsGeofenceViolationsAnalyticsProbe::forCompany(fleetOpsAnalyticsCompany('company-geofence'))->between($start, $end);

    $analytics->today  = 3;
    $analytics->period = 9;
    $analytics->dwells = collect([
        (object) [
            'driver_uuid'            => 'driver-uuid',
            'subject_name'           => 'Driver Name',
            'geofence_name'          => 'Warehouse',
            'dwell_duration_minutes' => '42',
            'occurred_at'            => '2026-07-11 10:00:00',
        ],
    ]);
    $analytics->zones = collect([
        (object) ['geofence_name' => 'Warehouse', 'total' => '5'],
        (object) ['geofence_name' => null, 'total' => '4'],
    ]);

    $result = $analytics->get();

    expect($result)->toBe([
        'violations_today'  => 3,
        'violations_period' => 9,
        'top_dwells'        => [
            [
                'driver_uuid'      => 'driver-uuid',
                'driver_name'      => 'Driver Name',
                'zone_name'        => 'Warehouse',
                'duration_minutes' => 42,
                'occurred_at'      => '2026-07-11 10:00:00',
            ],
        ],
        'by_zone' => [
            'labels' => ['Warehouse', 'Unnamed'],
            'data'   => [5, 4],
        ],
    ])
        ->and($analytics->calls)->toBe([
            ['today', 'company-geofence'],
            ['period', 'company-geofence', $start, $end],
            ['dwells', 'company-geofence', $start, $end],
            ['zones', 'company-geofence', $start, $end],
        ]);
});

test('maintenance overview analytics summarizes counts costs and upcoming maintenance', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 09:30:00'));

    $analytics                = FleetOpsMaintenanceOverviewAnalyticsProbe::forCompany(fleetOpsAnalyticsCompany('company-maint', 'SGD'));
    $analytics->overdue       = 2;
    $analytics->nextSevenDays = 4;
    $analytics->inProgress    = 1;
    $analytics->monthCost     = 1234.567;
    $analytics->ytdCost       = 9876.543;
    $analytics->upcoming      = collect([
        (object) [
            'uuid'         => 'maintenance-uuid',
            'type'         => 'scheduled',
            'priority'     => 'high',
            'scheduled_at' => '2026-07-28 10:00:00',
        ],
    ]);

    $result = $analytics->get();

    expect($result)->toBe([
        'overdue'           => 2,
        'scheduled_next_7d' => 4,
        'in_progress'       => 1,
        'cost_this_month'   => 1234.57,
        'cost_ytd'          => 9876.54,
        'currency'          => 'SGD',
        'upcoming'          => [
            [
                'uuid'         => 'maintenance-uuid',
                'type'         => 'scheduled',
                'priority'     => 'high',
                'scheduled_at' => '2026-07-28 10:00:00',
            ],
        ],
    ])
        ->and($analytics->calls)->toBe([
            ['overdue', 'company-maint', '2026-07-26 09:30:00'],
            ['next7d', 'company-maint', '2026-07-26 09:30:00'],
            ['in_progress', 'company-maint'],
            ['month_cost', 'company-maint', 'SGD', '2026-07-26 09:30:00'],
            ['ytd_cost', 'company-maint', 'SGD', '2026-07-26 09:30:00'],
            ['upcoming', 'company-maint', '2026-07-26 09:30:00'],
        ]);

    Carbon::setTestNow();
});

test('issues insights analytics builds category priority and resolution summaries', function () {
    $start     = new DateTimeImmutable('2026-07-01 00:00:00');
    $end       = new DateTimeImmutable('2026-07-31 23:59:59');
    $analytics = FleetOpsIssuesInsightsAnalyticsProbe::forCompany(fleetOpsAnalyticsCompany('company-issues'))->between($start, $end);

    $analytics->categories = collect([
        (object) ['category' => 'vehicle', 'total' => '3'],
        (object) ['category' => null, 'total' => '2'],
    ]);
    $analytics->priorities = collect([
        'high' => '5',
        'low'  => '1',
    ]);
    $analytics->open     = 6;
    $analytics->resolved = collect([
        (object) ['created_at' => '2026-07-10 08:00:00', 'resolved_at' => '2026-07-10 12:30:00'],
        (object) ['created_at' => '2026-07-11 10:00:00', 'resolved_at' => '2026-07-11 13:00:00'],
    ]);

    $result = $analytics->get();

    expect($result)->toBe([
        'by_category' => [
            'labels' => ['vehicle', 'Uncategorized'],
            'data'   => [3, 2],
        ],
        'by_priority' => [
            'high'   => 5,
            'medium' => 0,
            'low'    => 1,
        ],
        'open'                 => 6,
        'resolved_this_period' => 2,
        'avg_resolution_hours' => 3.8,
    ])
        ->and($analytics->calls)->toBe([
            ['category', 'company-issues', $start, $end],
            ['priority', 'company-issues', $start, $end],
            ['open', 'company-issues'],
            ['resolved', 'company-issues', $start, $end],
        ]);
});

test('issues insights analytics returns null average when no issues resolved in the period', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 09:30:00'));

    $analytics = FleetOpsIssuesInsightsAnalyticsProbe::forCompany(fleetOpsAnalyticsCompany('company-empty-issues'));

    $result = $analytics->get();

    expect($result['resolved_this_period'])->toBe(0)
        ->and($result['avg_resolution_hours'])->toBeNull()
        ->and($analytics->calls[0][0])->toBe('category')
        ->and($analytics->calls[0][1])->toBe('company-empty-issues')
        ->and($analytics->calls[0][2]->format('Y-m-d H:i:s'))->toBe('2026-06-26 09:30:00')
        ->and($analytics->calls[0][3]->format('Y-m-d H:i:s'))->toBe('2026-07-26 09:30:00');

    Carbon::setTestNow();
});
