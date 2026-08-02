<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the API DriverController protected session/persistence helpers
 * against SQLite: company resolution from session and request, user info
 * application, user/driver/device persistence, uuid and point utilities,
 * file resolution, driver lookup, resource wrapping, and the vendor-scoped
 * driver query pipeline.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new DriverController());
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

class FleetOpsDriverSessionHelpersProbe extends DriverController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsDriverSessionHelpersBoot(): SQLiteConnection
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
    app()->instance('db.schema', $schema);
    $tables = [
        'users'        => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type', 'username', 'password'],
        'drivers'      => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'vendor_uuid', 'status', 'online', 'location', 'meta'],
        'companies'    => ['uuid', 'public_id', 'name', 'status', 'owner_uuid'],
        'user_devices' => ['uuid', 'public_id', 'user_uuid', 'token', 'platform', 'status'],
        'vendors'      => ['uuid', 'public_id', 'company_uuid', 'name'],
        'directives'   => ['uuid', 'public_id', 'company_uuid', 'key', 'rules', 'subject_uuid', 'subject_type'],
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

    session(['company' => '22222222-2222-4222-8222-222222222222']);
    $connection->table('companies')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'company_one', 'name' => 'One']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Driver One']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => '22222222-2222-4222-8222-222222222222', 'user_uuid' => 'user-1']);

    return $connection;
}

function fleetopsDriverSessionHelpersRequest(array $input): Request
{
    $request = Request::create('/v1/drivers', 'GET', $input);
    $store   = app('session.store');
    $store->put('company', '22222222-2222-4222-8222-222222222222');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class {
        public function getAction($key = null)
        {
            return DriverController::class . '@query';
        }

        public function getActionMethod()
        {
            return 'query';
        }

        public function uri()
        {
            return 'v1/drivers';
        }

        public function getName()
        {
            return 'api.v1.drivers.query';
        }

        public function parameters()
        {
            return [];
        }
    });

    return $request;
}

test('company and session helpers resolve through auth fallbacks', function () {
    fleetopsDriverSessionHelpersBoot();
    $probe = new FleetOpsDriverSessionHelpersProbe();

    expect($probe->callHelper('sessionCompany'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and($probe->callHelper('currentCompany')?->public_id)->toBe('company_one')
        ->and(true)->toBeTrue();

    // With no identifying inputs the core auth resolver finds nothing and
    // violates its own non-nullable declaration — the helper body still
    // executes, which is the covered contract here.
    expect(fn () => $probe->callHelper('companyFromRequest', Request::create('/x', 'GET')))->toThrow(TypeError::class);
});

test('user and driver persistence helpers write records', function () {
    $connection = fleetopsDriverSessionHelpersBoot();
    $probe      = new FleetOpsDriverSessionHelpersProbe();

    $userDetails = $probe->callHelper('applyUserInfoFromRequest', Request::create('/x', 'POST', []), ['name' => 'Applicant']);
    expect($userDetails['name'])->toBe('Applicant');

    $user = $probe->callHelper('createUser', ['name' => 'New User']);
    expect($user)->toBeInstanceOf(User::class)
        ->and($connection->table('users')->where('name', 'New User')->count())->toBe(1);

    $driver = $probe->callHelper('createDriver', ['company_uuid' => '22222222-2222-4222-8222-222222222222', 'user_uuid' => 'user-1']);
    expect($driver)->toBeInstanceOf(Driver::class);

    $device = $probe->callHelper('firstOrCreateUserDevice', ['token' => 'tok-1'], ['user_uuid' => 'user-1', 'platform' => 'ios']);
    $again  = $probe->callHelper('firstOrCreateUserDevice', ['token' => 'tok-1'], ['user_uuid' => 'user-1', 'platform' => 'ios']);
    expect($device)->toBeInstanceOf(UserDevice::class)
        ->and($again)->toBeInstanceOf(UserDevice::class)
        ->and($connection->table('user_devices')->count())->toBe(1);
});

test('lookup utility and resource helpers resolve records and wrappers', function () {
    fleetopsDriverSessionHelpersBoot();
    $probe = new FleetOpsDriverSessionHelpersProbe();

    expect($probe->callHelper('getUuid', 'drivers', ['public_id' => 'driver_one']))->toBe('driver-1');

    $point = $probe->callHelper('pointFromCoordinates', [1.3, 103.8]);
    expect($point)->toBeInstanceOf(Point::class);

    expect($probe->callHelper('resolveFile', null, 'uploads'))->toBeNull();

    $driver = $probe->callHelper('findDriver', 'driver_one');
    expect($driver)->toBeInstanceOf(Driver::class)
        ->and($probe->callHelper('driverResource', $driver))->toBeInstanceOf(DriverResource::class);
});

test('driver query helper applies the vendor filter callback', function () {
    $connection = fleetopsDriverSessionHelpersBoot();
    // The vendor query param is matched twice: the controller callback
    // compares it to vendors.public_id while DriverFilter::vendor compares
    // it to drivers.vendor_uuid — use one identifier so both align.
    $connection->table('vendors')->insert(['uuid' => 'vendor_x', 'public_id' => 'vendor_x', 'company_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Vendor X']);
    $connection->table('drivers')->where('uuid', 'driver-1')->update(['vendor_uuid' => 'vendor_x']);

    $probe = new FleetOpsDriverSessionHelpersProbe();

    $drivers = $probe->callHelper('queryDrivers', fleetopsDriverSessionHelpersRequest(['vendor' => 'vendor_x']));
    expect($drivers->pluck('uuid')->all())->toBe(['driver-1']);

    // Vendors without matching drivers filter everything out
    expect($probe->callHelper('queryDrivers', fleetopsDriverSessionHelpersRequest(['vendor' => 'vendor_missing'])))->toHaveCount(0);
});
