<?php

use Fleetbase\FleetOps\Http\Filter\PartFilter;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

class FleetOpsPartFilterUnitQuery
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

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
}

class FleetOpsPartFilterUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsPartFilterUnitUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table vendors (uuid varchar(64), public_id varchar(64), internal_id varchar(64), company_uuid varchar(64), deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsPartFilterUnitDatabaseProbe($connection));

    return $connection;
}

function fleetopsPartFilterUnitFilter(FleetOpsPartFilterUnitQuery $builder): PartFilter
{
    $filter = (new ReflectionClass(PartFilter::class))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
        'request' => new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('part filter scopes tenant search and vendor public or internal identifiers', function () {
    $connection = fleetopsPartFilterUnitUseInMemoryConnection();
    $connection->table('vendors')->insert([
        [
            'uuid'         => 'vendor-public-uuid',
            'public_id'    => 'vendor_public',
            'internal_id'  => 'internal_other',
            'company_uuid' => 'company-uuid',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'vendor-internal-uuid',
            'public_id'    => 'vendor_other',
            'internal_id'  => 'vendor_internal',
            'company_uuid' => 'company-uuid',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'vendor-other-company-uuid',
            'public_id'    => 'vendor_public',
            'internal_id'  => 'vendor_internal',
            'company_uuid' => 'other-company',
            'deleted_at'   => null,
        ],
    ]);

    $builder = new FleetOpsPartFilterUnitQuery();
    $filter  = fleetopsPartFilterUnitFilter($builder);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('brake pad');
    $filter->vendor('vendor_public');
    $filter->vendor('vendor_internal');

    expect($builder->calls[0])->toBe(['where', ['company_uuid', 'company-uuid']])
        ->and($builder->calls[1])->toBe(['where', ['company_uuid', 'company-uuid']])
        ->and($builder->calls[2])->toBe(['search', 'brake pad'])
        ->and($builder->calls[3][0])->toBe('whereIn')
        ->and($builder->calls[3][1])->toBe('vendor_uuid')
        ->and($builder->calls[3][2]->all())->toBe(['vendor-public-uuid'])
        ->and($builder->calls[4][0])->toBe('whereIn')
        ->and($builder->calls[4][1])->toBe('vendor_uuid')
        ->and($builder->calls[4][2]->all())->toBe(['vendor-internal-uuid']);
});
