<?php

use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers Sensor::boot()'s creating hook, which defaults `last_position` to
 * POINT(0,0).
 *
 * The same migration that made devices.last_position NOT NULL did it to sensors
 * too, but only Device had a default. Creating a sensor through the API failed
 * with "Field 'last_position' doesn't have a default value" — the Postman
 * contract run caught it, having never been able to create a sensor at all.
 *
 * As with the device fixtures, the dispatcher is set up front so the model event
 * actually fires; without it the hook is never registered and its body never runs.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Observers\event')) {
    eval('namespace Fleetbase\FleetOps\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

function fleetopsSensorDefaultBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    // The creating hook is registered against whichever dispatcher is present
    // when the model boots, so it has to exist before Device is ever touched.
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }

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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('request', Request::create('/v1/sensors'));

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    $schema->create('sensors', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'internal_id', 'company_uuid', 'telematic_uuid', 'device_uuid', 'name', 'slug', 'type', 'status', 'online', 'unit', 'last_value', 'last_reading_at', 'min_threshold', 'max_threshold', 'location', 'last_position', 'meta', 'data', 'options', 'notes', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

test('creating a sensor without a position defaults it to the null island', function () {
    fleetopsSensorDefaultBoot();

    $sensor = Sensor::create([
        'company_uuid' => 'company-1',
        'name'         => 'Defaulted Sensor',
        'type'         => 'temperature',
    ]);

    expect($sensor->last_position)->toBeInstanceOf(SpatialPoint::class)
        ->and($sensor->last_position->getLat())->toBe(0.0)
        ->and($sensor->last_position->getLng())->toBe(0.0);
});

test('creating a sensor with a position leaves it untouched', function () {
    fleetopsSensorDefaultBoot();

    $sensor = Sensor::create([
        'company_uuid'  => 'company-1',
        'name'          => 'Positioned Sensor',
        'type'          => 'temperature',
        'last_position' => new SpatialPoint(1.3, 103.8),
    ]);

    expect($sensor->last_position)->toBeInstanceOf(SpatialPoint::class)
        ->and($sensor->last_position->getLat())->toBe(1.3)
        ->and($sensor->last_position->getLng())->toBe(103.8);
});
