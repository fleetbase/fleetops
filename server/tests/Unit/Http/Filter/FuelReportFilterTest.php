<?php

use Fleetbase\FleetOps\Http\Filter\FuelReportFilter;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Http\Request;

/**
 * Covers the FuelReportFilter query branches against a recording builder fake:
 * base scoping, keyword search, priority and status constraints, and the
 * assignee, reporter, driver and vehicle relation lookups routing by uuid,
 * public id, or fallback search.
 */
class FleetOpsFuelReportFilterUnitQuery
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        if (isset($arguments[0]) && is_callable($arguments[0])) {
            $nested = new self();
            $arguments[0]($nested);
            $this->calls[] = ['whereNested', $nested->calls];

            return $this;
        }

        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereHas(string $relation, ?callable $callback = null): self
    {
        $nested = new self();
        if ($callback) {
            $callback($nested);
        }
        $this->calls[] = ['whereHas', $relation, $nested->calls];

        return $this;
    }

    public function search(?string $query): self
    {
        $this->calls[] = ['search', $query];

        return $this;
    }

    public function whereIn(string $column, mixed $values): self
    {
        $this->calls[] = ['whereIn', $column, $values];

        return $this;
    }

    public function whereBetween(string $column, mixed $bounds): self
    {
        $this->calls[] = ['whereBetween', $column, $bounds];

        return $this;
    }

    public function whereDate(string $column, mixed $value): self
    {
        $this->calls[] = ['whereDate', $column, $value];

        return $this;
    }

    public function __call($method, $arguments): self
    {
        $this->calls[] = [$method, $arguments];

        return $this;
    }
}

function fleetopsFuelReportFilterUnitFilter(FleetOpsFuelReportFilterUnitQuery $builder, ?Request $request = null): FuelReportFilter
{
    $filter = (new ReflectionClass(FuelReportFilter::class))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
        'request' => $request ?? new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('fuel report filter scopes by driver_uuid and driver_assigned, not just driver', function () {
    foreach (['driverUuid', 'driverAssigned'] as $method) {
        $builder = new FleetOpsFuelReportFilterUnitQuery();
        $filter  = fleetopsFuelReportFilterUnitFilter($builder);

        $filter->{$method}('driver_filterone');

        $whereHas = collect($builder->calls)->where(0, 'whereHas')->firstWhere(1, 'driver');
        expect($whereHas)->not->toBeNull("{$method} must constrain the driver relation")
            ->and($whereHas[2][0][1][0])->toBe('public_id');
    }
});

test('fuel report filter driver aliases route uuids the same way driver does', function () {
    $builder = new FleetOpsFuelReportFilterUnitQuery();
    $filter  = fleetopsFuelReportFilterUnitFilter($builder);

    $filter->driverUuid('33333333-3333-4333-8333-333333333333');

    $whereHas = collect($builder->calls)->where(0, 'whereHas')->firstWhere(1, 'driver');
    expect($whereHas[2][0][1][0])->toBe('uuid');
});

test('fuel report filter scopes by vehicle_uuid as well as vehicle', function () {
    $builder = new FleetOpsFuelReportFilterUnitQuery();
    $filter  = fleetopsFuelReportFilterUnitFilter($builder);

    $filter->vehicleUuid('44444444-4444-4444-8444-444444444444');
    $filter->vehicleUuid('vehicle_filterone');

    $whereHasCalls = collect($builder->calls)->where(0, 'whereHas')->where(1, 'vehicle')->values();
    expect($whereHasCalls)->toHaveCount(2)
        ->and($whereHasCalls[0][2][0][1][0])->toBe('uuid')
        ->and($whereHasCalls[1][2][0][1][0])->toBe('public_id');
});
