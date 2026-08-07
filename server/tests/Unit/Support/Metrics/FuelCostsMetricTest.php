<?php

use Fleetbase\FleetOps\Support\Metrics\FuelCostsMetric;
use Fleetbase\Models\Company;

class FleetOpsFuelCostsMetricQueryFake
{
    public array $wheres        = [];
    public array $whereBetweens = [];
    public float $sum           = 0;

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }

    public function whereBetween(string $column, array $range): self
    {
        $this->whereBetweens[] = [$column, $range];

        return $this;
    }

    public function sum(string $column): float
    {
        expect($column)->toBe('amount');

        return $this->sum;
    }
}

class FleetOpsFuelCostsMetricProbe extends FuelCostsMetric
{
    public FleetOpsFuelCostsMetricQueryFake $query;
    public array $companyLookups = [];

    public function __construct()
    {
        $this->query = new FleetOpsFuelCostsMetricQueryFake();
    }

    public function queryForTest(?DateTimeInterface $start, ?DateTimeInterface $end): FleetOpsFuelCostsMetricQueryFake
    {
        return $this->query($start, $end);
    }

    public function aggregateForTest(FleetOpsFuelCostsMetricQueryFake $query): float
    {
        return $this->aggregate($query);
    }

    protected function fuelReportQuery(string $companyUuid): FleetOpsFuelCostsMetricQueryFake
    {
        $this->companyLookups[] = $companyUuid;

        return $this->query;
    }
}

test('fuel costs metric builds company currency and date range query', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-uuid',
        'currency' => 'SGD',
    ], true);

    $metric = FleetOpsFuelCostsMetricProbe::forCompany($company);
    $start  = new DateTimeImmutable('2026-08-01 00:00:00');
    $end    = new DateTimeImmutable('2026-08-31 23:59:59');

    $query = $metric->queryForTest($start, $end);

    expect($query)->toBe($metric->query)
        ->and($metric->companyLookups)->toBe(['company-uuid'])
        ->and($query->wheres)->toBe([
            ['currency', 'SGD'],
        ])
        ->and($query->whereBetweens)->toBe([
            ['created_at', [$start, $end]],
        ]);
});

test('fuel costs metric skips date filter without complete range and sums amount', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-uuid',
        'currency' => 'USD',
    ], true);

    $metric             = FleetOpsFuelCostsMetricProbe::forCompany($company);
    $metric->query->sum = 88.45;

    $query = $metric->queryForTest(new DateTimeImmutable('2026-08-01'), null);

    expect($query->wheres)->toBe([
        ['currency', 'USD'],
    ])
        ->and($query->whereBetweens)->toBe([])
        ->and($metric->aggregateForTest($query))->toBe(88.45);
});
