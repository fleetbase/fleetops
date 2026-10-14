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

/**
 * A filter whose request resolves to an internal console route.
 *
 * `Http::isInternalRequest()` reads the resolved route's uri, so the console's
 * uuid pass-through is only reachable through a route resolver.
 */
function fleetopsInternalOrderFilter(FleetOpsRecordingOrderFilterBuilder $builder): OrderFilter
{
    $uri     = 'int/v1/fleet-ops/orders';
    $request = Request::create('/' . $uri, 'GET');
    $session = app('session.store');
    $session->put('company', 'company_test');
    $request->setLaravelSession($session);
    $request->setRouteResolver(fn () => new class($uri) {
        public array $action = [];

        public function __construct(private string $uri)
        {
        }

        public function uri(): string
        {
            return $this->uri;
        }
    });

    $filter     = new OrderFilter($request);
    $reflection = new ReflectionClass($filter);
    $property   = $reflection->getParentClass()->getProperty('builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    return $filter;
}

/**
 * Customer and facilitator filters resolve public ids to uuids, which is a real query.
 *
 * @return array<string, array<string, string>>
 */
function fleetopsOrderFilterRelationDatabase(): array
{
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public Illuminate\Database\SQLiteConnection $c)
        {
        }

        public function connection($name = null)
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    foreach (['vendors', 'integrated_vendors', 'contacts'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', '_key'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $rows = [
        'vendors'            => ['uuid' => '77777777-7777-4777-8777-777777777701', 'public_id' => 'vendor_ofilterone', 'internal_id' => 'VND-1'],
        'integrated_vendors' => ['uuid' => '77777777-7777-4777-8777-777777777702', 'public_id' => 'integrated_vendor_ofilter'],
        'contacts'           => ['uuid' => '77777777-7777-4777-8777-777777777703', 'public_id' => 'contact_ofilterone'],
    ];

    foreach ($rows as $table => $row) {
        $connection->table($table)->insert($row + ['company_uuid' => 'company_test']);
    }

    // Another tenant's vendor is never reachable by its public id.
    $connection->table('vendors')->insert(['uuid' => '77777777-7777-4777-8777-777777777709', 'public_id' => 'vendor_otherco', 'company_uuid' => 'another-company']);

    return $rows;
}

/**
 * @return array<int, array<int, string>>
 */
function fleetopsOrderFilterWhereInValues(FleetOpsRecordingOrderFilterBuilder $builder, string $column): array
{
    return collect($builder->methodCalls('whereIn'))
        ->filter(fn ($call) => $call[1] === $column)
        ->map(fn ($call) => $call[2])
        ->values()
        ->all();
}

test('order filter resolves facilitator public ids across vendors integrated vendors and contacts', function () {
    $rows    = fleetopsOrderFilterRelationDatabase();
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);

    $filter->facilitator($rows['vendors']['public_id']);
    $filter->facilitator($rows['vendors']['internal_id']);
    $filter->facilitator($rows['integrated_vendors']['public_id']);
    $filter->facilitator($rows['contacts']['public_id']);
    // The public API never accepts a raw uuid, an unknown id, or another tenant's id.
    $filter->facilitator($rows['vendors']['uuid']);
    $filter->facilitator('vendor_missing');
    $filter->facilitator('vendor_otherco');

    // The filter used to compare the public id against `facilitator_uuid`
    // directly, which could never match. An id that resolves to nothing now
    // yields an empty list, so it matches no order rather than every order.
    expect(fleetopsOrderFilterWhereInValues($builder, 'facilitator_uuid'))->toBe([
        [$rows['vendors']['uuid']],
        [$rows['vendors']['uuid']],
        [$rows['integrated_vendors']['uuid']],
        [$rows['contacts']['uuid']],
        [],
        [],
        [],
    ]);
});

test('order filter resolves customer public ids and keeps the customer portal user match', function () {
    $rows    = fleetopsOrderFilterRelationDatabase();
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);

    $filter->customer($rows['contacts']['public_id']);
    $filter->customer($rows['vendors']['public_id']);
    $filter->customer('portal-user-uuid');

    $userMatches = collect($builder->methodCalls('orWhereHas'))
        ->filter(fn ($call) => $call[1] === 'authenticatableCustomer')
        ->map(fn ($call) => $call[2]->calls)
        ->values()
        ->all();

    expect(fleetopsOrderFilterWhereInValues($builder, 'customer_uuid'))->toBe([
        [$rows['contacts']['uuid']],
        [$rows['vendors']['uuid']],
        [],
    ])
        // A customer portal user still reaches their orders by user uuid.
        ->and($userMatches[2])->toBe([['where', 'user_uuid', ['portal-user-uuid']]]);
});

test('order filter keeps console uuids working for customer and facilitator', function () {
    $rows    = fleetopsOrderFilterRelationDatabase();
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsInternalOrderFilter($builder);

    $filter->customer($rows['contacts']['uuid']);
    $filter->facilitator($rows['integrated_vendors']['uuid']);

    expect(fleetopsOrderFilterWhereInValues($builder, 'customer_uuid'))->toBe([[$rows['contacts']['uuid']]])
        ->and(fleetopsOrderFilterWhereInValues($builder, 'facilitator_uuid'))->toBe([[$rows['integrated_vendors']['uuid']]]);
});

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
    $filter->authenticatedCustomer('user_uuid');
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

    // Batch label scans arrive as one comma-separated list of internal ids; blanks are ignored.
    $filter->internalId('ORD-1, ORD-2,');
    $filter->internalId(' ');

    expect($builder->called('whereIn'))->toBeTrue()
        ->and($builder->calls)->toContain(['whereIn', 'internal_id', ['ORD-1', 'ORD-2']])
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

test('order filter inverse date branches bulk public ids and eager scopes', function () {
    $builder = new FleetOpsRecordingOrderFilterBuilder();
    $filter  = fleetopsOrderFilter($builder);

    // Inverse date branches and valid public-id bulk lookups
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-02-15');
    $filter->scheduledAt('2026-02-20');
    $filter->bulkQuery(['order_bulkhash01', 'order_bulkhash02']);

    expect(collect($builder->methodCalls('whereBetween'))->count())->toBeGreaterThanOrEqual(1)
        ->and(collect($builder->methodCalls('whereDate'))->count())->toBeGreaterThanOrEqual(2)
        ->and($builder->called('whereIn'))->toBeTrue();

    // The internal eager-load prunes driver and vehicle sub-relations
    $scoped       = new FleetOpsRecordingOrderFilterBuilder();
    $scopedFilter = fleetopsOrderFilter($scoped);
    $scopedFilter->queryForInternal();
    $withCall  = collect($scoped->methodCalls('with'))->first();
    $relations = $withCall[1] ?? [];
    foreach (['driverAssigned', 'vehicleAssigned'] as $relation) {
        if (isset($relations[$relation]) && $relations[$relation] instanceof Closure) {
            $relations[$relation]($scoped);
        }
    }
    expect(collect($scoped->methodCalls('without'))->count())->toBeGreaterThanOrEqual(2);
});
