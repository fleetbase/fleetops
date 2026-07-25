<?php

use Fleetbase\FleetOps\Http\Filter\OrderFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FleetOpsRecordingOrderFilterBuilder
{
    public array $calls = [];

    public function search($query, ?Closure $callback = null)
    {
        $this->calls[] = ['search', $query];

        if ($callback) {
            $callback($this, $query);
        }

        return $this;
    }

    public function where($column, ...$args)
    {
        $this->calls[] = ['where', $column, $args];
        $this->invokeNested($column);

        return $this;
    }

    public function orWhere($column, ...$args)
    {
        $this->calls[] = ['orWhere', $column, $args];
        $this->invokeNested($column);

        return $this;
    }

    public function whereHas($relation, ?Closure $callback = null)
    {
        $nested        = new self();
        $this->calls[] = ['whereHas', $relation, $nested];
        $callback?->call($this, $nested);

        return $this;
    }

    public function orWhereHas($relation, ?Closure $callback = null)
    {
        $nested        = new self();
        $this->calls[] = ['orWhereHas', $relation, $nested];
        $callback?->call($this, $nested);

        return $this;
    }

    public function whereDoesntHave($relation, ?Closure $callback = null)
    {
        $nested        = new self();
        $this->calls[] = ['whereDoesntHave', $relation, $nested];
        $callback?->call($this, $nested);

        return $this;
    }

    public function removeWhereFromQuery($column, $value)
    {
        $this->calls[] = ['removeWhereFromQuery', $column, $value];

        return $this;
    }

    public function __call($method, $arguments)
    {
        $this->calls[] = [$method, ...$arguments];

        foreach ($arguments as $argument) {
            $this->invokeNested($argument);
        }

        return $this;
    }

    public function methodCalls(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] === $method));
    }

    public function called(string $method): bool
    {
        return !empty($this->methodCalls($method));
    }

    private function invokeNested($value): void
    {
        if (!$value instanceof Closure) {
            return;
        }

        $reflection = new ReflectionFunction($value);
        if ($reflection->getNumberOfParameters() > 0) {
            $value($this);

            return;
        }

        $value();
    }
}

function fleetopsOrderFilter(FleetOpsRecordingOrderFilterBuilder $builder, array $query = []): OrderFilter
{
    $request = Request::create('/int/v1/orders', 'GET', $query);
    $session = app('session.store');
    $session->put('company', 'company_test');
    $request->setLaravelSession($session);

    $filter = new OrderFilter($request);

    $reflection = new ReflectionClass($filter);
    $property   = $reflection->getParentClass()->getProperty('builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    return $filter;
}

test('order filter applies internal and public base scopes with eager loading', function () {
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);

    $filter->queryForInternal();
    $filter->queryForPublic();

    expect($builder->called('where'))->toBeTrue()
        ->and($builder->methodCalls('where')[0][1])->toBe('orders.company_uuid')
        ->and($builder->called('whereHas'))->toBeTrue()
        ->and($builder->called('with'))->toBeTrue();
});

test('order filter search and assignment status filters execute nested query branches', function () {
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);

    $filter->query('needle');
    $filter->unassigned(true);
    $filter->unassigned(false);
    $filter->active(true);
    $filter->active(false);
    $filter->tracking('TRACK123');

    expect($builder->called('search'))->toBeTrue()
        ->and($builder->called('whereDoesntHave'))->toBeTrue()
        ->and($builder->called('whereNotIn'))->toBeTrue()
        ->and($builder->called('whereHas'))->toBeTrue();
});

test('order filter identity and relation filters support uuid and public identifiers', function () {
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);
    $uuid    = (string) Str::uuid();

    $filter->status('active');
    $filter->status(['created', 'started']);
    $filter->customer('customer_uuid');
    $filter->authenticatedCustomer('user_uuid');
    $filter->facilitator('facilitator_uuid');
    $filter->type('transport');
    $filter->orderConfig('transport');
    $filter->payload($uuid);
    $filter->payload('payload_public');
    $filter->only(['order_one', 'order_two']);
    $filter->pickup($uuid);
    $filter->pickup('pickup_public');
    $filter->dropoff($uuid);
    $filter->dropoff('dropoff_public');
    $filter->return($uuid);
    $filter->return('return_public');
    $filter->vehicle($uuid);
    $filter->vehicle('vehicle_public');
    $filter->driver($uuid);
    $filter->driver('driver_public');
    $filter->driverAssigned('driver_public');

    expect($builder->called('whereIn'))->toBeTrue()
        ->and($builder->called('removeWhereFromQuery'))->toBeTrue()
        ->and($builder->called('whereHas'))->toBeTrue()
        ->and($builder->called('orWhereHas'))->toBeTrue();
});

test('order filter sort exclude bulk and date filters execute their query operations', function () {
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);
    $uuid    = (string) Str::uuid();

    foreach (['tracking:asc', 'customer:desc', 'facilitator:asc', 'pickup:desc', 'dropoff:asc'] as $sort) {
        $filter->sort($sort);
    }

    $filter->exclude([$uuid, (string) Str::uuid()]);
    $filter->exclude(['order_public']);
    $filter->bulkQuery(['order_public']);
    $filter->bulkQuery([$uuid]);
    $filter->bulkQuery(['internal_1']);
    $filter->createdAt('2026-01-01');
    $filter->updatedAt(['2026-01-01', '2026-01-31']);
    $filter->scheduledAt(['2026-02-01', '2026-02-02']);
    $filter->withoutDriver(true);
    $filter->withoutDriver(false);

    expect($builder->called('join'))->toBeTrue()
        ->and($builder->called('orderBy'))->toBeTrue()
        ->and($builder->called('whereNotIn'))->toBeTrue()
        ->and($builder->called('whereDate'))->toBeTrue()
        ->and($builder->called('whereBetween'))->toBeTrue()
        ->and($builder->called('whereNull'))->toBeTrue();
});
