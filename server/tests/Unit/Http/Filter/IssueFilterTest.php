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
