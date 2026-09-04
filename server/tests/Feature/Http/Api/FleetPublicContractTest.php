<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Models\Fleet;
use Illuminate\Http\Request;

// The query pipeline reaches Fleetbase\Support helpers that call the framework's
// auth() and session() helpers; neither exists in the bare-container harness.
if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new FleetController());
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

class FleetOpsFleetResourceRouteFixture
{
    public array $action = [];

    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function getAction($key = null): string
    {
        return FleetController::class . '@query';
    }

    public function getActionMethod(): string
    {
        return 'query';
    }

    public function getName(): string
    {
        return 'api.v1.fleets.query';
    }

    public function parameters(): array
    {
        return [];
    }
}

function fleetopsFleetResourceRequest(bool $internal, array $query = []): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/fleets/fleet_123' : 'api/v1/fleets/fleet_123';
    $request = Request::create('/' . $uri, 'GET', $query);
    $request->setRouteResolver(fn () => new FleetOpsFleetResourceRouteFixture($uri));
    app()->instance('request', $request);

    return $request;
}

/**
 * Boot an in-memory database holding one fleet with every relationship set.
 *
 * The resource resolves each assignment to a public id, which is a real read,
 * and the custom-field merge and photo accessor both touch the database as
 * well — so the shape of the public payload can only be asserted against a
 * real connection.
 */
function fleetopsFleetResourceDatabase(): Illuminate\Database\SQLiteConnection
{
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);

    app()->instance('db', new class($connection) {
        public function __construct(public Illuminate\Database\SQLiteConnection $c)
        {
        }

        public function connection($name = null): Illuminate\Database\SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();

    $schema->create('fleets', function ($blueprint) {
        $blueprint->increments('id');
        foreach ([
            'uuid', '_key', 'public_id', 'company_uuid', 'service_area_uuid', 'zone_uuid',
            'vendor_uuid', 'parent_fleet_uuid', 'image_uuid', 'name', 'color', 'task', 'status', 'slug',
        ] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    foreach (['service_areas', 'zones', 'vendors'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', '_key', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'type', 'border', 'status'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    foreach (['fleet_drivers' => 'driver_uuid', 'fleet_vehicles' => 'vehicle_uuid'] as $table => $subject) {
        $schema->create($table, function ($blueprint) use ($subject) {
            $blueprint->increments('id');
            $blueprint->string('uuid')->nullable();
            $blueprint->string('fleet_uuid')->nullable();
            $blueprint->string($subject)->nullable();
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    foreach (['drivers', 'vehicles', 'users'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->boolean('online')->nullable();
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    // Permission directives are consulted by the query pipeline.
    $schema->create('directives', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    // The fleet image relation is eager loaded alongside the others.
    $schema->create('files', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'disk', 'path', 'bucket', 'type', 'original_filename'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $schema->create('custom_field_values', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $connection->table('service_areas')->insert(['uuid' => 'service-area-uuid', 'public_id' => 'service_area_123', 'company_uuid' => 'company-uuid', 'name' => 'Central']);
    $connection->table('zones')->insert(['uuid' => 'zone-uuid', 'public_id' => 'zone_123', 'company_uuid' => 'company-uuid', 'name' => 'Downtown']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-uuid', 'public_id' => 'vendor_123', 'company_uuid' => 'company-uuid', 'name' => 'Acme']);
    $connection->table('fleets')->insert(['uuid' => 'parent-uuid', 'public_id' => 'fleet_parent123', 'company_uuid' => 'company-uuid', 'name' => 'Parent']);

    return $connection;
}

function fleetopsFleetResourceFixture(array $overrides = []): Fleet
{
    $connection = fleetopsFleetResourceDatabase();

    $connection->table('fleets')->insert(array_merge([
        'uuid'              => 'fleet-uuid',
        'public_id'         => 'fleet_123',
        'company_uuid'      => 'company-uuid',
        'name'              => 'Carpool',
        'color'             => '#2563EB',
        'task'              => 'Employee transport',
        'status'            => 'active',
        'service_area_uuid' => 'service-area-uuid',
        'zone_uuid'         => 'zone-uuid',
        'vendor_uuid'       => 'vendor-uuid',
        'parent_fleet_uuid' => 'parent-uuid',
    ], $overrides));

    return Fleet::where('uuid', 'fleet-uuid')->firstOrFail();
}

test('the public fleet resource reports every writable field and each relationship as a public id', function () {
    $payload = (new FleetResource(fleetopsFleetResourceFixture()))->resolve(fleetopsFleetResourceRequest(false));

    // A caller that just wrote `parent_fleet: "fleet_parent123"` has to be able
    // to read the assignment back, and the public contract never exposes the
    // uuid the column actually holds.
    expect($payload)->toMatchArray([
        'id'           => 'fleet_123',
        'name'         => 'Carpool',
        'color'        => '#2563EB',
        'task'         => 'Employee transport',
        'status'       => 'active',
        'service_area' => 'service_area_123',
        'zone'         => 'zone_123',
        'vendor'       => 'vendor_123',
        'parent_fleet' => 'fleet_parent123',
    ])->and($payload)->not->toHaveKeys([
        'uuid',
        'public_id',
        'company_uuid',
        'service_area_uuid',
        'zone_uuid',
        'vendor_uuid',
        'parent_fleet_uuid',
        'image_uuid',
    ]);
});

test('the public fleet resource reports a root fleet with a null parent', function () {
    $fleet = fleetopsFleetResourceFixture(['parent_fleet_uuid' => null]);

    $payload = (new FleetResource($fleet))->resolve(fleetopsFleetResourceRequest(false));

    expect($payload)->toHaveKey('parent_fleet')
        ->and($payload['parent_fleet'])->toBeNull();
});

test('the internal fleet resource keeps its nested relationship shape', function () {
    $fleet = fleetopsFleetResourceFixture()->load(['serviceArea', 'zone', 'vendor', 'parentFleet']);

    $payload = (new FleetResource($fleet))->resolve(fleetopsFleetResourceRequest(true));

    // The console reads nested objects off these keys, and reads the counters;
    // expanding the public contract must not reshape either.
    expect($payload)->toHaveKeys(['uuid', 'public_id', 'drivers_count', 'vehicles_count'])
        ->and($payload['uuid'])->toBe('fleet-uuid')
        ->and($payload['service_area'])->toBeObject()
        ->and($payload['zone'])->toBeObject()
        ->and($payload['vendor'])->toBeObject()
        ->and($payload['parent_fleet'])->toBeObject();
});

test('the public fleet resource still nests a relationship the caller asked for', function () {
    $request = fleetopsFleetResourceRequest(false, ['with' => ['service_area']]);
    $payload = (new FleetResource(fleetopsFleetResourceFixture()))->resolve($request);

    expect($payload['service_area'])->toBeObject()
        // Relations that were not requested stay as public ids.
        ->and($payload['vendor'])->toBe('vendor_123');
});

test('public fleet membership routes are registered with public id parameters', function () {
    $routes = file_get_contents(dirname(__DIR__, 4) . '/src/routes.php');

    expect($routes)
        ->toContain("\$router->post('{id}/vehicles/{vehicle}', 'FleetController@assignVehicle');")
        ->toContain("\$router->delete('{id}/vehicles/{vehicle}', 'FleetController@removeVehicle');")
        ->toContain("\$router->post('{id}/drivers/{driver}', 'FleetController@assignDriver');")
        ->toContain("\$router->delete('{id}/drivers/{driver}', 'FleetController@removeDriver');");

    $fleetGroup = substr($routes, strpos($routes, '// fleets routes'));
    $fleetGroup = substr($fleetGroup, 0, strpos($fleetGroup, '// labels routes'));

    // The literal segments have to be declared before the `{id}` patterns, or
    // `vehicles` is swallowed as a fleet id.
    expect(strpos($fleetGroup, '{id}/vehicles/{vehicle}'))->toBeLessThan(strpos($fleetGroup, "\$router->get('{id}'"));
});

test('the public fleet controller exposes the membership actions', function () {
    foreach (['create', 'query', 'find', 'update', 'delete', 'assignVehicle', 'removeVehicle', 'assignDriver', 'removeDriver'] as $method) {
        expect(method_exists(FleetController::class, $method))->toBeTrue();
    }
});

test('the fleet controller lookup query and eager-load helpers run their real bodies', function () {
    $connection = fleetopsFleetResourceDatabase();
    session(['company' => 'company-uuid']);

    $connection->table('fleets')->insert([
        ['uuid' => 'fleet-uuid', 'public_id' => 'fleet_123', 'company_uuid' => 'company-uuid', 'name' => 'Haulers', 'service_area_uuid' => 'service-area-uuid', 'parent_fleet_uuid' => 'parent-uuid'],
        ['uuid' => 'other-company-fleet', 'public_id' => 'fleet_elsewhere', 'company_uuid' => 'another-company', 'name' => 'Theirs', 'service_area_uuid' => null, 'parent_fleet_uuid' => null],
    ]);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-uuid', 'public_id' => 'vehicle_123', 'company_uuid' => 'company-uuid']);
    $connection->table('users')->insert(['uuid' => 'user-uuid', 'company_uuid' => 'company-uuid']);
    $connection->table('drivers')->insert(['uuid' => 'driver-uuid', 'public_id' => 'driver_123', 'company_uuid' => 'company-uuid', 'user_uuid' => 'user-uuid']);

    $controller = new FleetController();
    $call       = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(FleetController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    $fleet = $call('findFleet', 'fleet_123');

    expect($fleet->uuid)->toBe('fleet-uuid')
        ->and($call('findVehicle', 'vehicle_123')->uuid)->toBe('vehicle-uuid')
        ->and($call('findDriver', 'driver_123')->uuid)->toBe('driver-uuid');

    // A fleet in another company is unavailable, not forbidden.
    expect(fn () => $call('findFleet', 'fleet_elsewhere'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    // The relations the public resource reports as public ids are eager loaded
    // rather than resolved one query at a time.
    $loaded = $call('withPublicRelations', $fleet);

    expect($loaded->relationLoaded('serviceArea'))->toBeTrue()
        ->and($loaded->relationLoaded('zone'))->toBeTrue()
        ->and($loaded->relationLoaded('vendor'))->toBeTrue()
        ->and($loaded->relationLoaded('parentFleet'))->toBeTrue()
        ->and($loaded->relationLoaded('photo'))->toBeTrue()
        ->and($loaded->serviceArea->public_id)->toBe('service_area_123');

    // The query pipeline scopes to the caller's company and eager loads the
    // same relations for every row in the page.
    $uri     = 'v1/fleets';
    $request = Request::create('/' . $uri, 'GET');
    $store   = app('session.store');
    $store->put('company', 'company-uuid');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new FleetOpsFleetResourceRouteFixture($uri));
    app()->instance('request', $request);

    $results = $call('queryFleets', $request);

    $returned = $results->pluck('uuid')->all();

    expect($returned)->toContain('fleet-uuid', 'parent-uuid')
        ->and($returned)->not->toContain('other-company-fleet')
        ->and($results->first()->relationLoaded('serviceArea'))->toBeTrue()
        ->and($results->first()->relationLoaded('parentFleet'))->toBeTrue();
});
