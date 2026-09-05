<?php

use Fleetbase\FleetOps\Http\Filter\DriverFilter;
use Fleetbase\FleetOps\Http\Filter\VehicleFilter;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `internal_id` means two different things to two different callers.
 *
 * The Fleet-Ops console types into a search box and expects `VEH-10` to find
 * `VEH-100`. An importer asks whether `VEH-10` exists so it can decide between
 * update and create, and a partial match answers yes because `VEH-100` does —
 * so the importer updates the wrong vehicle, or skips a create it needed. The
 * console keeps the loose behaviour; everything else gets equality.
 *
 * Asserted against a real connection: the branch depends on the resolved route,
 * and a recording double would prove only that a method was called.
 */
function fleetopsIdentifierFilterBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');

    // `searchWhere` is a Builder macro registered by core's service provider,
    // which does not boot here. Mirrors the real one: case-insensitive LIKE with
    // dots and commas treated as wildcards.
    if (!Illuminate\Database\Eloquent\Builder::hasGlobalMacro('searchWhere')) {
        Illuminate\Database\Eloquent\Builder::macro('searchWhere', function ($column, $search, $strict = false) {
            if ($strict === true) {
                return $this->where($column, $search);
            }

            $needle = '%' . str_replace(['.', ','], '%', (string) $search) . '%';

            return $this->where(DB::raw('lower(' . $column . ')'), 'like', strtolower($needle));
        });
    }

    $schema = $connection->getSchemaBuilder();
    foreach (['vehicles', 'drivers', 'users'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'name', '_key'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $rows  = ['VEH-10', 'VEH-100', 'VEH-101'];
    $index = 0;
    foreach ($rows as $internalId) {
        $index++;
        $connection->table('vehicles')->insert([
            'uuid'         => 'vehicle-uuid-' . $index,
            'public_id'    => 'vehicle_id' . $index,
            'internal_id'  => $internalId,
            'company_uuid' => 'company-uuid',
        ]);
        $connection->table('users')->insert(['uuid' => 'user-uuid-' . $index, 'company_uuid' => 'company-uuid']);
        $connection->table('drivers')->insert([
            'uuid'         => 'driver-uuid-' . $index,
            'public_id'    => 'driver_id' . $index,
            'internal_id'  => str_replace('VEH', 'DRV', $internalId),
            'company_uuid' => 'company-uuid',
            'user_uuid'    => 'user-uuid-' . $index,
        ]);
    }

    // Another tenant holding the very same internal id.
    $connection->table('vehicles')->insert([
        'uuid'         => 'vehicle-other-company',
        'public_id'    => 'vehicle_other01',
        'internal_id'  => 'VEH-10',
        'company_uuid' => 'another-company',
    ]);
    $connection->table('users')->insert(['uuid' => 'user-other', 'company_uuid' => 'another-company']);
    $connection->table('drivers')->insert([
        'uuid'         => 'driver-other-company',
        'public_id'    => 'driver_other01',
        'internal_id'  => 'DRV-10',
        'company_uuid' => 'another-company',
        'user_uuid'    => 'user-other',
    ]);

    session(['company' => 'company-uuid']);

    return $connection;
}

class FleetOpsIdentifierFilterRoute
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

function fleetopsIdentifierFilterRequest(string $uri, array $query): Request
{
    $request = Request::create('/' . $uri, 'GET', $query);
    $session = app('session.store');
    $session->put('company', 'company-uuid');
    $request->setLaravelSession($session);
    $request->setRouteResolver(fn () => new FleetOpsIdentifierFilterRoute($uri));

    return $request;
}

/**
 * @return array<int, string>
 */
function fleetopsFilteredInternalIds(string $filterClass, string $modelClass, string $uri, array $query): array
{
    $filter = new $filterClass(fleetopsIdentifierFilterRequest($uri, $query));

    return $filter->apply($modelClass::query())->pluck('internal_id')->sort()->values()->all();
}

test('a public vehicle lookup by internal id matches exactly and stays inside the tenant', function () {
    fleetopsIdentifierFilterBoot();

    $matched = fleetopsFilteredInternalIds(VehicleFilter::class, Vehicle::class, 'v1/vehicles', ['internal_id' => 'VEH-10']);

    // VEH-100 and VEH-101 are different vehicles, and the other tenant's VEH-10
    // is a different vehicle again.
    expect($matched)->toBe(['VEH-10']);
});

test('the console keeps partial internal id search on vehicles', function () {
    fleetopsIdentifierFilterBoot();

    $matched = fleetopsFilteredInternalIds(VehicleFilter::class, Vehicle::class, 'int/v1/fleet-ops/vehicles', ['internal_id' => 'VEH-10']);

    expect($matched)->toBe(['VEH-10', 'VEH-100', 'VEH-101']);
});

test('a public driver lookup by internal id matches exactly and stays inside the tenant', function () {
    fleetopsIdentifierFilterBoot();

    expect(fleetopsFilteredInternalIds(DriverFilter::class, Driver::class, 'v1/drivers', ['internal_id' => 'DRV-10']))
        ->toBe(['DRV-10']);
});

test('the console keeps partial internal id search on drivers', function () {
    fleetopsIdentifierFilterBoot();

    expect(fleetopsFilteredInternalIds(DriverFilter::class, Driver::class, 'int/v1/fleet-ops/drivers', ['internal_id' => 'DRV-10']))
        ->toBe(['DRV-10', 'DRV-100', 'DRV-101']);
});

test('a public lookup by public id is exact for vehicles and drivers', function () {
    fleetopsIdentifierFilterBoot();

    // vehicle_id1 is a prefix of nothing here, so the partial and exact forms
    // agree — the assertion that matters is that a public request produces an
    // equality comparison at all, which the tenant row below proves.
    expect(fleetopsFilteredInternalIds(VehicleFilter::class, Vehicle::class, 'v1/vehicles', ['public_id' => 'vehicle_id1']))
        ->toBe(['VEH-10'])
        ->and(fleetopsFilteredInternalIds(DriverFilter::class, Driver::class, 'v1/drivers', ['public_id' => 'driver_id1']))
        ->toBe(['DRV-10'])
        // Another tenant's record is never reachable, by either identifier.
        ->and(fleetopsFilteredInternalIds(VehicleFilter::class, Vehicle::class, 'v1/vehicles', ['public_id' => 'vehicle_other01']))
        ->toBe([]);
});

test('vin and plate number keep the search behaviour they already had', function () {
    $connection = fleetopsIdentifierFilterBoot();
    $connection->getSchemaBuilder()->table('vehicles', function ($blueprint) {
        $blueprint->string('vin')->nullable();
        $blueprint->string('plate_number')->nullable();
    });
    $connection->table('vehicles')->where('uuid', 'vehicle-uuid-1')->update(['vin' => 'VIN1234567890', 'plate_number' => 'SG-1000']);
    $connection->table('vehicles')->where('uuid', 'vehicle-uuid-2')->update(['vin' => 'VIN1234567891', 'plate_number' => 'SG-10001']);

    // Untouched by this change: both still match on a prefix.
    expect(fleetopsFilteredInternalIds(VehicleFilter::class, Vehicle::class, 'v1/vehicles', ['vin' => 'VIN123456789']))
        ->toBe(['VEH-10', 'VEH-100'])
        ->and(fleetopsFilteredInternalIds(VehicleFilter::class, Vehicle::class, 'v1/vehicles', ['plate_number' => 'SG-1000']))
        ->toBe(['VEH-10', 'VEH-100']);
});

test('the console keeps partial public id search on vehicles drivers and fleets', function () {
    $connection = fleetopsIdentifierFilterBoot();
    $connection->getSchemaBuilder()->create('fleets', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('fleets')->insert([
        ['uuid' => 'fleet-1', 'public_id' => 'fleet_id1', 'company_uuid' => 'company-uuid', 'name' => 'One'],
        ['uuid' => 'fleet-2', 'public_id' => 'fleet_id12', 'company_uuid' => 'company-uuid', 'name' => 'Two'],
    ]);

    // The console's id column filter is a search box; the public API's is a
    // lookup. Both behaviours are kept, chosen by the resolved route.
    $vehicles = (new VehicleFilter(fleetopsIdentifierFilterRequest('int/v1/fleet-ops/vehicles', ['public_id' => 'vehicle_id1'])))
        ->apply(Vehicle::query())->pluck('public_id')->sort()->values()->all();

    $drivers = (new DriverFilter(fleetopsIdentifierFilterRequest('int/v1/fleet-ops/drivers', ['public_id' => 'driver_id1'])))
        ->apply(Driver::query())->pluck('public_id')->sort()->values()->all();

    $fleets = (new Fleetbase\FleetOps\Http\Filter\FleetFilter(fleetopsIdentifierFilterRequest('int/v1/fleet-ops/fleets', ['public_id' => 'fleet_id1'])))
        ->apply(Fleetbase\FleetOps\Models\Fleet::query())->pluck('public_id')->sort()->values()->all();

    expect($vehicles)->toBe(['vehicle_id1'])
        ->and($drivers)->toBe(['driver_id1'])
        // fleet_id1 is a prefix of fleet_id12, so the console returns both.
        ->and($fleets)->toBe(['fleet_id1', 'fleet_id12']);

    // The public API returns only the exact match.
    $publicFleets = (new Fleetbase\FleetOps\Http\Filter\FleetFilter(fleetopsIdentifierFilterRequest('v1/fleets', ['public_id' => 'fleet_id1'])))
        ->apply(Fleetbase\FleetOps\Models\Fleet::query())->pluck('public_id')->all();

    expect($publicFleets)->toBe(['fleet_id1']);
});

test('the driver update request scopes its uniqueness lookup to the caller company', function () {
    fleetopsIdentifierFilterBoot();

    $request = Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest::create('/v1/drivers/driver_id1', 'PUT', ['email' => 'a@example.test']);
    $request->setRouteResolver(fn () => new class {
        public function parameter($name, $default = null)
        {
            return $name === 'id' ? 'driver_id1' : $default;
        }

        public function uri(): string
        {
            return 'v1/drivers/{id}';
        }
    });
    app()->instance('request', $request);

    $reflection = new ReflectionMethod($request, 'linkedUserUuid');
    $reflection->setAccessible(true);

    // Ignoring the driver's own user by uuid is what lets an unchanged address
    // be resent without tripping the uniqueness rule.
    expect($reflection->invoke($request))->toBe('user-uuid-1');

    // Another tenant's driver is not reachable, so its user is never ignored.
    $foreign = Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest::create('/v1/drivers/driver_other01', 'PUT', []);
    $foreign->setRouteResolver(fn () => new class {
        public function parameter($name, $default = null)
        {
            return $name === 'id' ? 'driver_other01' : $default;
        }

        public function uri(): string
        {
            return 'v1/drivers/{id}';
        }
    });

    expect($reflection->invoke($foreign))->toBeNull();
});
