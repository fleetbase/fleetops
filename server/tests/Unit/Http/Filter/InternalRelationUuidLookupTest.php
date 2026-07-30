<?php

use Fleetbase\Http\Filter\Filter;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the shared `resolvePublicRelationUuids()` seam that DeviceFilter and
 * SensorFilter each carry: relation identifiers normally resolve by `public_id`
 * or `internal_id`, but an internal request additionally allows a raw `uuid` to
 * be passed. Public requests must not accept uuids, so the two request kinds are
 * asserted against each other.
 */
class FleetOpsInternalFilterRouteFixture
{
    public array $action = [];

    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

function fleetopsInternalFilterRequest(string $uri): Request
{
    $request = Request::create('/' . $uri, 'GET');
    $request->setRouteResolver(fn () => new FleetOpsInternalFilterRouteFixture($uri));

    return $request;
}

function fleetopsInternalFilterBoot(array $tables): SQLiteConnection
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

    return $connection;
}

function fleetopsInternalFilterMake(string $filterClass, Request $request, $builder): object
{
    $filter = (new ReflectionClass($filterClass))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
        'request' => $request,
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('internal requests let relation filters resolve records by raw uuid', function () {
    $connection = fleetopsInternalFilterBoot([
        'vehicles' => ['uuid', 'public_id', 'company_uuid', 'internal_id', 'name'],
        'devices'  => ['uuid', 'public_id', 'company_uuid', 'attachable_uuid', 'attachable_type', 'name'],
        'sensors'  => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'name'],
    ]);

    $connection->table('vehicles')->insert(['uuid' => 'veh-int-1', 'public_id' => 'vehicle_intone', 'company_uuid' => 'company-uuid', 'internal_id' => 'VEH-INT', 'name' => 'Internal Van']);
    session(['company' => 'company-uuid']);

    $resolve = function (string $filterClass, string $uri, string $modelClass, string $identifier) {
        $filter     = fleetopsInternalFilterMake($filterClass, fleetopsInternalFilterRequest($uri), $modelClass::query());
        $reflection = new ReflectionMethod($filterClass, 'resolvePublicRelationUuids');
        $reflection->setAccessible(true);

        return $reflection->invoke(
            $filter,
            $modelClass,
            $identifier,
            Fleetbase\Support\Http::isInternalRequest(fleetopsInternalFilterRequest($uri))
        );
    };

    $vehicle = Fleetbase\FleetOps\Models\Vehicle::class;

    foreach ([
        Fleetbase\FleetOps\Http\Filter\DeviceFilter::class,
        Fleetbase\FleetOps\Http\Filter\SensorFilter::class,
    ] as $filterClass) {
        // An internal route resolves the raw uuid, a public route does not
        expect($resolve($filterClass, 'int/v1/fleet-ops/records', $vehicle, 'veh-int-1')->all())->toBe(['veh-int-1'])
            ->and($resolve($filterClass, 'v1/records', $vehicle, 'veh-int-1')->all())->toBe([]);

        // Public and internal ids resolve regardless of the route kind
        expect($resolve($filterClass, 'v1/records', $vehicle, 'vehicle_intone')->all())->toBe(['veh-int-1'])
            ->and($resolve($filterClass, 'v1/records', $vehicle, 'VEH-INT')->all())->toBe(['veh-int-1']);
    }
});
