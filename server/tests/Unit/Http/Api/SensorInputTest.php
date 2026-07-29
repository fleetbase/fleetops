<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers input mapping on the sensor API: a filled `sensorable` resolves to
 * its morph type and uuid, while a blank one clears both.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsSensorInputBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    foreach (['vehicles', 'devices', 'sensors'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status', 'sensorable_uuid', 'sensorable_type', '_key'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-sensor-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-sensor-1', 'public_id' => 'vehicle_sensorone', 'company_uuid' => 'company-sensor-1']);

    return $connection;
}

test('sensor input resolves and clears its morph target', function () {
    fleetopsSensorInputBoot();

    $class      = Fleetbase\FleetOps\Http\Controllers\Api\v1\SensorController::class;
    $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionMethod($class, 'input');
    $reflection->setAccessible(true);

    // A filled sensorable resolves to the morph class and its uuid
    $attached = $reflection->invoke($controller, Request::create('/v1/sensors', 'POST', [
        'name'            => 'Cargo Temp',
        'sensorable'      => 'vehicle_sensorone',
        // the short name resolves to a nonexistent \Fleetbase\Models\Vehicle
        'sensorable_type' => Fleetbase\FleetOps\Models\Vehicle::class,
    ]));

    expect($attached['sensorable_uuid'])->toBe('vehicle-sensor-1')
        ->and($attached['sensorable_type'])->toContain('Vehicle');

    // An explicitly blank sensorable detaches the sensor
    $detached = $reflection->invoke($controller, Request::create('/v1/sensors', 'POST', [
        'name'       => 'Cargo Temp',
        'sensorable' => '',
    ]));

    expect($detached['sensorable_uuid'])->toBeNull()
        ->and($detached['sensorable_type'])->toBeNull();
});
