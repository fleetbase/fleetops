<?php

use Fleetbase\FleetOps\Http\Filter\IssueFilter;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Http\Request;

/**
 * Covers the IssueFilter query branches against a recording builder fake:
 * base scoping, keyword search, priority and status constraints, and the
 * assignee, reporter, driver and vehicle relation lookups routing by uuid,
 * public id, or fallback search.
 */
class FleetOpsIssueFilterUnitQuery
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

function fleetopsIssueFilterUnitFilter(FleetOpsIssueFilterUnitQuery $builder, ?Request $request = null): IssueFilter
{
    $filter = (new ReflectionClass(IssueFilter::class))->newInstanceWithoutConstructor();

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

test('issue filter relation branches route uuids public ids and searches', function () {
    $builder = new FleetOpsIssueFilterUnitQuery();
    $filter  = fleetopsIssueFilterUnitFilter($builder);

    $filter->assignee('11111111-1111-4111-8111-111111111111');
    $filter->assignee('user_assigneeone');
    $filter->assignee('casey');
    $filter->reporter('22222222-2222-4222-8222-222222222222');
    $filter->reporter('reporter name');
    $filter->driver('driver_filterone');
    $filter->driver('33333333-3333-4333-8333-333333333333');
    $filter->vehicle('vehicle_filterone');
    $filter->vehicle('van search');
    $filter->status(['open', 'resolved']);
    $filter->priority('high');
    $filter->createdAt('2026-07-01');
    $filter->updatedAt(['2026-07-01', '2026-07-15']);

    $whereHasCalls = collect($builder->calls)->where(0, 'whereHas');
    expect($whereHasCalls)->toHaveCount(9)
        ->and($whereHasCalls->firstWhere(1, 'assignedTo')[2][0][0])->toBe('where')
        ->and(collect($builder->calls)->firstWhere(0, 'whereIn')[2])->toBe(['open', 'resolved'])
        ->and(collect($builder->calls)->where(0, 'whereDate'))->toHaveCount(1)
        ->and(collect($builder->calls)->where(0, 'whereBetween'))->toHaveCount(1);

    // Search fallbacks execute inside the relation closures
    $searchNested = $whereHasCalls->filter(fn ($call) => collect($call[2])->contains(fn ($inner) => $inner[0] === 'search'));
    expect($searchNested->count())->toBeGreaterThanOrEqual(3);
});

test('issue filter scopes by driver_uuid and driver_assigned, not just driver', function () {
    /*
     * The base filter resolves a query parameter to a method of the same name
     * and silently ignores what it cannot match. `driver_uuid` matched nothing,
     * so the filter was dropped and the list came back scoped only by company —
     * every driver's issues, with a 200 and no sign the request had been
     * narrowed. For a driver app that is a disclosure, not a nuisance.
     */
    foreach (['driverUuid', 'driverAssigned'] as $method) {
        $builder = new FleetOpsIssueFilterUnitQuery();
        $filter  = fleetopsIssueFilterUnitFilter($builder);

        $filter->{$method}('driver_filterone');

        $whereHas = collect($builder->calls)->where(0, 'whereHas')->firstWhere(1, 'driver');
        expect($whereHas)->not->toBeNull("{$method} must constrain the driver relation")
            ->and($whereHas[2][0][1][0])->toBe('public_id');
    }
});

test('issue filter driver aliases route uuids the same way driver does', function () {
    $builder = new FleetOpsIssueFilterUnitQuery();
    $filter  = fleetopsIssueFilterUnitFilter($builder);

    $filter->driverUuid('33333333-3333-4333-8333-333333333333');

    $whereHas = collect($builder->calls)->where(0, 'whereHas')->firstWhere(1, 'driver');
    expect($whereHas[2][0][1][0])->toBe('uuid');
});

test('issue filter scopes by vehicle_uuid as well as vehicle', function () {
    $builder = new FleetOpsIssueFilterUnitQuery();
    $filter  = fleetopsIssueFilterUnitFilter($builder);

    $filter->vehicleUuid('44444444-4444-4444-8444-444444444444');
    $filter->vehicleUuid('vehicle_filterone');

    $whereHasCalls = collect($builder->calls)->where(0, 'whereHas')->where(1, 'vehicle')->values();
    expect($whereHasCalls)->toHaveCount(2)
        ->and($whereHasCalls[0][2][0][1][0])->toBe('uuid')
        ->and($whereHasCalls[1][2][0][1][0])->toBe('public_id');
});
