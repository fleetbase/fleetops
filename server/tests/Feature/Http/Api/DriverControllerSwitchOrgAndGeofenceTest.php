<?php

if (!class_exists('Fleetbase\Http\Requests\SwitchOrganizationRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class SwitchOrganizationRequest extends \Illuminate\Http\Request {}');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function put($k, $v = null) { \session([$k => $v]); return true; } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function event($event = null, $payload = []) { $GLOBALS["fleetopsDriverGeofenceEvents"][] = $event; return []; }');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Http\Requests\SwitchOrganizationRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the API DriverController switchOrganization flow against SQLite
 * with a stand-in form request: driver-not-found responses, successful
 * organization switches updating the user session and issuing sanctum
 * tokens for the target driver profile, and the geofence crossing
 * processor upserting entry states, skipping untriggered entries, and
 * closing exited states with dwell calculations.
 */
class FleetOpsDriverGeofenceProbe extends DriverController
{
    public function callGeofence(...$arguments): void
    {
        $method = new ReflectionMethod(DriverController::class, 'processSubjectGeofenceCrossings');
        $method->setAccessible(true);
        $method->invoke($this, ...$arguments);
    }
}

function fleetopsDriverSwitchBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'drivers'                => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'location', 'online', 'status', 'token', '_key'],
        'users'                  => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'status', '_key'],
        'companies'              => ['uuid', 'public_id', 'name', 'country', 'owner_uuid', 'logo_uuid', 'backdrop_uuid', 'place_uuid', 'timezone', 'currency', 'options', 'slug', 'status', '_key'],
        'company_users'          => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'personal_access_tokens' => ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at'],
        'vehicles'               => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'online'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['online', 'is_inside'], true)) {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    // The upsert requires a unique constraint on the state key pair
    $schema->create('driver_geofence_states', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['driver_uuid', 'geofence_uuid', 'geofence_type', 'entered_at', 'exited_at', 'dwell_job_id'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->integer('is_inside')->nullable();
        $blueprint->timestamps();
        $blueprint->unique(['driver_uuid', 'geofence_uuid']);
    });

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    session(['company' => 'company-1', 'user' => 'user-1']);

    return $connection;
}

test('switch organization moves the driver session to the next company', function () {
    $connection = fleetopsDriverSwitchBoot();
    $connection->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_switch1', 'name' => 'Acme A', 'country' => 'SG'],
        ['uuid' => 'company-2', 'public_id' => 'company_switch2', 'name' => 'Acme B', 'country' => 'SG'],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-1', 'public_id' => 'user_switch1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'type' => 'user']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-2', 'user_uuid' => 'user-1']);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'public_id' => 'driver_switch1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1'],
        ['uuid' => 'driver-2', 'public_id' => 'driver_switch2', 'company_uuid' => 'company-2', 'user_uuid' => 'user-1'],
    ]);

    $request = SwitchOrganizationRequest::create('/v1/drivers/driver_switch1/switch-organization', 'POST', ['next' => 'company_switch2']);

    $controller = new DriverController();
    $result     = $controller->switchOrganization('driver_switch1', $request);

    expect($result)->toBeArray()
        ->and($result['driver']->resource->uuid)->toBe('driver-2')
        ->and($connection->table('users')->where('uuid', 'user-1')->value('company_uuid'))->toBe('company-2')
        ->and($connection->table('personal_access_tokens')->count())->toBe(1);
});

test('switch organization returns not found for unknown drivers', function () {
    fleetopsDriverSwitchBoot();

    $request  = SwitchOrganizationRequest::create('/v1/drivers/missing/switch-organization', 'POST', ['next' => 'company_switch2']);
    $response = (new DriverController())->switchOrganization('driver_missing', $request);

    expect($response->getStatusCode())->toBe(404);
});

test('geofence crossings upsert entries skip untriggered and close exits', function () {
    $connection = fleetopsDriverSwitchBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_geo1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $driver = Driver::query()->where('uuid', 'driver-1')->first();
    $driver->setRelation('vehicle', null);

    $geofenceEntry = (object) ['uuid' => 'geo-1', 'public_id' => 'geofence_geo1', 'name' => 'Zone One', 'trigger_on_entry' => true, 'trigger_on_exit' => true, 'dwell_threshold_minutes' => null];
    $geofenceSkip  = (object) ['uuid' => 'geo-2', 'public_id' => 'geofence_geo2', 'name' => 'Zone Two', 'trigger_on_entry' => false, 'trigger_on_exit' => false, 'dwell_threshold_minutes' => null];

    $GLOBALS['fleetopsDriverGeofenceEvents'] = [];
    $probe                                   = new FleetOpsDriverGeofenceProbe();
    $location                                = new Point(1.30, 103.80);

    $probe->callGeofence($driver, $location, 'driver_geofence_states', 'driver_uuid', [
        ['type' => 'entered', 'geofence' => $geofenceEntry, 'geofence_type' => 'zone'],
        ['type' => 'entered', 'geofence' => $geofenceSkip, 'geofence_type' => 'zone'],
    ]);

    expect($connection->table('driver_geofence_states')->count())->toBe(1)
        ->and((int) $connection->table('driver_geofence_states')->value('is_inside'))->toBe(1)
        ->and($GLOBALS['fleetopsDriverGeofenceEvents'])->toHaveCount(1);

    $probe->callGeofence($driver, $location, 'driver_geofence_states', 'driver_uuid', [
        ['type' => 'exited', 'geofence' => $geofenceEntry, 'geofence_type' => 'zone'],
    ]);

    $state = $connection->table('driver_geofence_states')->first();
    expect((int) $state->is_inside)->toBe(0)
        ->and($state->exited_at)->not->toBeNull()
        ->and($GLOBALS['fleetopsDriverGeofenceEvents'])->toHaveCount(2);
});

test('geofence entries with dwell thresholds schedule dwell checks', function () {
    $connection = fleetopsDriverSwitchBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_dwell1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $driver = Driver::query()->where('uuid', 'driver-1')->first();
    $driver->setRelation('vehicle', null);

    $geofenceDwell = (object) ['uuid' => 'geo-3', 'public_id' => 'geofence_geo3', 'name' => 'Dwell Zone', 'trigger_on_entry' => true, 'trigger_on_exit' => false, 'dwell_threshold_minutes' => 5];

    $GLOBALS['fleetopsDriverGeofenceEvents']            = [];
    Fleetbase\TestSupport\DispatchRecorder::$dispatched = [];
    (new FleetOpsDriverGeofenceProbe())->callGeofence($driver, new Point(1.30, 103.80), 'driver_geofence_states', 'driver_uuid', [
        ['type' => 'entered', 'geofence' => $geofenceDwell, 'geofence_type' => 'zone'],
    ]);

    expect(collect(Fleetbase\TestSupport\DispatchRecorder::$dispatched)->pluck('job'))->toContain(Fleetbase\FleetOps\Jobs\CheckGeofenceDwell::class)
        ->and($connection->table('driver_geofence_states')->value('dwell_job_id'))->not->toBeNull();
});

test('switch organization rejects same-session foreign and profileless targets', function () {
    $connection = fleetopsDriverSwitchBoot();
    $connection->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_switch1', 'name' => 'Acme A', 'country' => 'SG'],
        ['uuid' => 'company-2', 'public_id' => 'company_switch2', 'name' => 'Acme B', 'country' => 'SG'],
        ['uuid' => 'company-3', 'public_id' => 'company_switch3', 'name' => 'Acme C', 'country' => 'SG'],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-1', 'public_id' => 'user_switch1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'type' => 'user']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-2', 'user_uuid' => 'user-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_switch1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $controller = new DriverController();

    // Switching to the company the driver is already sessioned on
    $same = $controller->switchOrganization('driver_switch1', SwitchOrganizationRequest::create('/x', 'POST', ['next' => 'company_switch1']));
    expect($same->getData(true)['error'])->toContain('already on this organization');

    // Switching to an organization the user has no membership in
    $foreign = $controller->switchOrganization('driver_switch1', SwitchOrganizationRequest::create('/x', 'POST', ['next' => 'company_switch3']));
    expect($foreign->getData(true)['error'])->toContain('does not belong');

    // Member of the organization but without a driver profile there
    $noProfile = $controller->switchOrganization('driver_switch1', SwitchOrganizationRequest::create('/x', 'POST', ['next' => 'company_switch2']));
    expect($noProfile->getData(true)['error'])->toContain('driver profile');
});

test('driver company resolution and response seams execute their bodies', function () {
    $connection = fleetopsDriverSwitchBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_drvseam1', 'name' => 'Acme', 'country' => 'SG']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'public_id' => 'user_drvseam1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'type' => 'driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_drvseam1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $probe  = new FleetOpsDriverGeofenceProbe();
    $method = function (string $name, ...$arguments) use ($probe) {
        $reflection = new ReflectionMethod(DriverController::class, $name);
        $reflection->setAccessible(true);

        return $reflection->invoke($probe, ...$arguments);
    };

    expect($method('jsonResponse', ['ok' => true], 200)->getData(true))->toBe(['ok' => true])
        ->and($method('apiError', 'nope', 400)->getStatusCode())->toBe(400);

    $user   = Fleetbase\Models\User::where('uuid', 'user-1')->first();
    $driver = Driver::where('uuid', 'driver-1')->first();
    expect($method('driverResource', $driver))->not->toBeNull()
        ->and($method('driverResourceCollection', collect([$driver])))->not->toBeNull()
        ->and($method('deletedDriverResource', $driver))->not->toBeNull();

    // Driver profile company resolution walks profiles then session fallback
    $static = new ReflectionMethod(DriverController::class, 'getDriverCompanyFromUser');
    $static->setAccessible(true);
    expect($static->invoke(null, $user)?->uuid)->toBe('company-1');
});
