<?php

use Fleetbase\FleetOps\Support\Analytics\GeofenceViolations;
use Fleetbase\FleetOps\Support\Analytics\OrdersByStatus;
use Fleetbase\Models\Company;

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
