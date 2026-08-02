<?php

use Fleetbase\FleetOps\Http\Filter\FuelProviderTransactionFilter;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the FuelProviderTransactionFilter public-relation resolution: the
 * missing-identifier early return, the public-id/internal-id/uuid matching
 * branches of resolvePublicRelationUuids against a real SQLite fixture, and
 * the internal-request uuid allowance.
 */
class FleetOpsFuelTxnFilterQuery
{
    public array $calls = [];

    public function __call($method, $arguments)
    {
        $this->calls[] = [$method, $arguments];

        return $this;
    }
}

function fleetopsFuelTxnFilterBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
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
    $tables = [
        'vehicles'                  => ['uuid', 'public_id', 'internal_id', 'company_uuid'],
        'fuel_provider_connections' => ['uuid', 'public_id', 'company_uuid', 'provider', 'credentials'],
        'drivers'                   => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid'],
        'users'                     => ['uuid', 'public_id', 'company_uuid'],
        'orders'                    => ['uuid', 'public_id', 'internal_id', 'company_uuid'],
        'fuel_reports'              => ['uuid', 'public_id', 'company_uuid', 'report'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsFuelTxnFilter(FleetOpsFuelTxnFilterQuery $builder, ?Request $request = null): FuelProviderTransactionFilter
{
    $filter = (new ReflectionClass(FuelProviderTransactionFilter::class))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-1' : null;
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

function fleetopsFuelTxnInternalRequest(): Request
{
    $request = new Request();
    $request->setRouteResolver(fn () => new class {
        public array $action = ['namespace' => 'Fleetbase\FleetOps\Http\Controllers\Internal'];

        public function uri(): string
        {
            return 'int/v1/fuel-provider-transactions';
        }
    });

    return $request;
}

test('missing identifiers skip relation constraints entirely', function () {
    fleetopsFuelTxnFilterBoot();
    $builder = new FleetOpsFuelTxnFilterQuery();
    $filter  = fleetopsFuelTxnFilter($builder);

    $filter->vehicle(null);
    $filter->connection(null);
    $filter->driver(null);
    $filter->order(null);
    $filter->fuelReport(null);

    expect($builder->calls)->toBe([]);
});

test('public relations resolve uuids by public and internal ids', function () {
    $connection = fleetopsFuelTxnFilterBoot();
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'public_id' => 'vehicle_abc', 'internal_id' => 'FLEET-9', 'company_uuid' => 'company-1'],
        ['uuid' => 'vehicle-2', 'public_id' => 'vehicle_other', 'internal_id' => 'OTHER', 'company_uuid' => 'company-2'],
    ]);
    $connection->table('fuel_provider_connections')->insert(['uuid' => 'conn-1', 'public_id' => 'fuel_provider_connection_abc', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_abc', 'internal_id' => 'ORD-7', 'company_uuid' => 'company-1']);
    $connection->table('fuel_reports')->insert(['uuid' => 'fr-1', 'public_id' => 'fuel_report_abc', 'company_uuid' => 'company-1']);

    $builder = new FleetOpsFuelTxnFilterQuery();
    $filter  = fleetopsFuelTxnFilter($builder);

    $filter->vehicle('vehicle_abc');
    $filter->connection('fuel_provider_connection_abc');
    $filter->order('ORD-7');
    $filter->fuelReport('fuel_report_abc');

    $whereIns = collect($builder->calls)->where(0, 'whereIn')->values();
    expect($whereIns)->toHaveCount(4)
        ->and($whereIns[0][1][0])->toBe('vehicle_uuid')
        ->and($whereIns[0][1][1]->all())->toBe(['vehicle-1'])
        ->and($whereIns[1][1][1]->all())->toBe(['conn-1'])
        ->and($whereIns[2][1][1]->all())->toBe(['order-1'])
        ->and($whereIns[3][1][1]->all())->toBe(['fr-1']);

    // Company scoping excludes other companies' matches
    $filter->vehicle('vehicle_other');
    $scoped = collect($builder->calls)->where(0, 'whereIn')->last();
    expect($scoped[1][1]->all())->toBe([]);
});

test('internal requests may resolve relations by raw uuid', function () {
    $connection = fleetopsFuelTxnFilterBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_abc', 'internal_id' => 'FLEET-9', 'company_uuid' => 'company-1']);

    // Public requests cannot match by uuid
    $publicBuilder = new FleetOpsFuelTxnFilterQuery();
    fleetopsFuelTxnFilter($publicBuilder)->vehicle('vehicle-1');
    expect(collect($publicBuilder->calls)->where(0, 'whereIn')->last()[1][1]->all())->toBe([]);

    // Internal requests can
    $internalBuilder = new FleetOpsFuelTxnFilterQuery();
    fleetopsFuelTxnFilter($internalBuilder, fleetopsFuelTxnInternalRequest())->vehicle('vehicle-1');
    expect(collect($internalBuilder->calls)->where(0, 'whereIn')->last()[1][1]->all())->toBe(['vehicle-1']);
});
