<?php

use Fleetbase\FleetOps\Http\Filter\DriverFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FleetOpsRecordingDriverFilterBuilder
{
    public array $calls = [];

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

    public function searchWhere($columns, $query)
    {
        $this->calls[] = ['searchWhere', $columns, $query];

        return $this;
    }

    public function search($query)
    {
        $this->calls[] = ['search', $query];

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

    public function called(string $method): bool
    {
        return !empty($this->methodCalls($method));
    }

    public function methodCalls(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] === $method));
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

function fleetopsDriverFilter(FleetOpsRecordingDriverFilterBuilder $builder, array $query = []): DriverFilter
{
    $request = Request::create('/int/v1/drivers', 'GET', $query);
    $session = app('session.store');
    $session->put('company', 'company_test');
    $request->setLaravelSession($session);

    $filter     = new DriverFilter($request);
    $reflection = new ReflectionClass($filter);
    $property   = $reflection->getParentClass()->getProperty('builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    return $filter;
}

test('driver filter applies internal public and text search scopes', function () {
    $builder = new FleetOpsRecordingDriverFilterBuilder();
    $filter  = fleetopsDriverFilter($builder);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('driver needle');
    $filter->internalId('driver-internal');
    $filter->name('Jane Driver');
    $filter->publicId('driver_public');

    expect($builder->called('where'))->toBeTrue()
        ->and($builder->called('whereHas'))->toBeTrue()
        ->and($builder->called('orWhereHas'))->toBeTrue()
        ->and($builder->called('searchWhere'))->toBeTrue();
});

test('driver filter assignment and identity filters execute uuid and public branches', function () {
    $builder = new FleetOpsRecordingDriverFilterBuilder();
    $filter  = fleetopsDriverFilter($builder);
    $uuid    = (string) Str::uuid();

    $filter->facilitator('vendor_uuid');
    $filter->vendor('vendor_uuid');
    $filter->vehicle('unassigned');
    $filter->vehicle($uuid);
    $filter->vehicle('vehicle_public');
    $filter->driversLicenseNumber('DL-123');
    $filter->phone('+15551112222');
    $filter->country('SG,MY');
    $filter->country('MN');
    $filter->status('available,offline');
    $filter->fleet('fleet_uuid');

    expect($builder->called('whereNull'))->toBeTrue()
        ->and($builder->called('where'))->toBeTrue()
        ->and($builder->called('whereHas'))->toBeTrue()
        ->and($builder->called('whereIn'))->toBeTrue()
        ->and($builder->called('searchWhere'))->toBeTrue();
});

test('driver filter date and nearby coordinate filters execute query operations', function () {
    $builder = new FleetOpsRecordingDriverFilterBuilder();
    $filter  = fleetopsDriverFilter($builder, ['radius' => 1200]);

    $filter->createdAt('2026-01-01');
    $filter->updatedAt(['2026-01-01', '2026-01-31']);
    $filter->nearby('1.3000,103.8000');

    expect($builder->called('whereDate'))->toBeTrue()
        ->and($builder->called('whereBetween'))->toBeTrue()
        ->and($builder->called('whereNotNull'))->toBeTrue()
        ->and($builder->called('whereRaw'))->toBeTrue()
        ->and($builder->called('distanceSphere'))->toBeTrue()
        ->and($builder->called('distanceSphereValue'))->toBeTrue();
});

test('driver filter covers alternate date branches company radius and address nearby', function () {
    // Array created-at ranges and scalar updated-at dates hit the inverse branches
    $builder = new FleetOpsRecordingDriverFilterBuilder();
    $filter  = fleetopsDriverFilter($builder, ['radius' => 900]);
    $filter->createdAt(['2026-01-01', '2026-01-31']);
    $filter->updatedAt('2026-01-15');
    expect($builder->called('whereBetween'))->toBeTrue()
        ->and($builder->called('whereDate'))->toBeTrue();

    // Without a radius input the company option and hard default provide distances
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
    $schema->create('companies', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'name', 'options'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('companies')->insert(['uuid' => 'company_test', 'name' => 'Filter Co', 'options' => json_encode(['fleetops' => ['adhoc_distance' => 2500]])]);

    // No radius, no company: the hard 6000m default applies
    session(['company' => null]);
    $defaultBuilder = new FleetOpsRecordingDriverFilterBuilder();
    $defaultFilter  = fleetopsDriverFilter($defaultBuilder, []);
    $defaultFilter->nearby('1.3000,103.8000');
    expect($defaultBuilder->called('distanceSphere'))->toBeTrue();

    session(['company' => 'company_test']);
    $companyBuilder = new FleetOpsRecordingDriverFilterBuilder();
    $companyFilter  = fleetopsDriverFilter($companyBuilder, []);
    $companyFilter->nearby('1.3000,103.8000');
    expect($companyBuilder->called('distanceSphere'))->toBeTrue();

    // Address-string nearby lookups resolve places and add distance queries
    $wkbPoint = pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', 103.8) . pack('d', 1.3);
    $connection->table('places')->insert(['uuid' => 'place-filter-1', 'company_uuid' => 'company_test', 'name' => 'Filter Depot', 'street1' => 'Filter Depot Road', 'location' => $wkbPoint]);
    $address = Geocoder\Provider\GoogleMaps\Model\GoogleAddress::createFromArray([
        'providedBy' => 'google',
        'latitude'   => 1.3,
        'longitude'  => 103.8,
        'streetName' => 'Filter Depot Road',
        'locality'   => 'Singapore',
        'country'    => 'Singapore',
    ]);
    app()->instance('fleetops.geocoder', new class($address) {
        public function __construct(public $address)
        {
        }

        public function geocodeQuery($query)
        {
            return new Geocoder\Model\AddressCollection([$this->address]);
        }

        public function reverseQuery($query)
        {
            return new Geocoder\Model\AddressCollection([$this->address]);
        }
    });
    $addressBuilder = new FleetOpsRecordingDriverFilterBuilder();
    $addressFilter  = fleetopsDriverFilter($addressBuilder, ['radius' => 800]);
    $addressFilter->nearby('Filter Depot');
    expect($addressBuilder->called('distanceSphere'))->toBeTrue()
        ->and($addressBuilder->called('distanceSphereValue'))->toBeTrue();
});
