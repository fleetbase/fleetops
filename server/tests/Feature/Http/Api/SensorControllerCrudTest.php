<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\SensorController;
use Fleetbase\FleetOps\Http\Requests\CreateSensorRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateSensorRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Sensor as SensorResource;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the public API SensorController CRUD endpoints against an in-memory
 * SQLite fixture: create with company scoping, query with eager loads, find,
 * update, delete, and the not-found error branches of each lookup endpoint.
 */
if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new SensorController());
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

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Traits\session')) {
    eval('namespace Fleetbase\Traits; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsApiSensorBoot(): SQLiteConnection
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema        = $connection->getSchemaBuilder();
    $sensorColumns = [
        'uuid', 'public_id', 'company_uuid', 'name', 'type', 'internal_id', 'imei', 'imsi',
        'firmware_version', 'serial_number', 'unit', 'min_threshold', 'max_threshold',
        'threshold_inclusive', 'last_reading_at', 'last_value', 'calibration',
        'report_frequency_sec', 'status', 'meta', 'last_position', 'device_uuid',
        'telematic_uuid', 'warranty_uuid', 'photo_uuid', 'sensorable_type', 'sensorable_uuid',
    ];
    $schema->create('sensors', function ($table) use ($sensorColumns) {
        $table->increments('id');
        foreach ($sensorColumns as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('directives', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    foreach (['devices', 'telematics', 'warranties', 'files'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'type', 'original_filename'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('create endpoint persists a company scoped sensor', function () {
    $connection = fleetopsApiSensorBoot();

    $request = CreateSensorRequest::create('/v1/sensors', 'POST', [
        'name'        => 'Temperature Sensor',
        'type'        => 'temperature',
        'internal_id' => 'SENSOR-001',
        'status'      => 'active',
    ]);
    $resource = (new SensorController())->create($request);

    expect($resource)->toBeInstanceOf(SensorResource::class)
        ->and($connection->table('sensors')->count())->toBe(1)
        ->and($connection->table('sensors')->value('company_uuid'))->toBe('company-1')
        ->and($connection->table('sensors')->value('internal_id'))->toBe('SENSOR-001');
});

test('create endpoint rejects uuid identifiers in the payload', function () {
    fleetopsApiSensorBoot();

    $request = CreateSensorRequest::create('/v1/sensors', 'POST', [
        'name'        => 'Bad Sensor',
        'device_uuid' => 'device-uuid-value',
    ]);

    expect(fn () => (new SensorController())->create($request))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('query endpoint lists sensors with eager loads', function () {
    $connection = fleetopsApiSensorBoot();
    $connection->table('sensors')->insert([
        'uuid'         => 'sensor-1',
        'internal_id'  => 'SENSOR-001',
        'company_uuid' => 'company-1',
        'name'         => 'Temp',
    ]);

    $request = Request::create('/v1/sensors', 'GET');
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class {
        public function getAction($key = null)
        {
            return SensorController::class . '@query';
        }

        public function getActionMethod()
        {
            return 'query';
        }

        public function uri()
        {
            return 'v1/sensors';
        }

        public function getName()
        {
            return 'api.v1.sensors.query';
        }

        public function parameters()
        {
            return [];
        }
    });

    $result = (new SensorController())->query($request);

    expect($result->count())->toBe(1);
});

test('find endpoint resolves by internal id and reports missing sensors', function () {
    $connection = fleetopsApiSensorBoot();
    $connection->table('sensors')->insert([
        'uuid'         => 'sensor-1',
        'internal_id'  => 'SENSOR-001',
        'company_uuid' => 'company-1',
        'name'         => 'Temp',
    ]);

    $controller = new SensorController();

    expect($controller->find('SENSOR-001'))->toBeInstanceOf(SensorResource::class);

    $missing = $controller->find('missing');
    expect($missing)->toBeInstanceOf(JsonResponse::class)
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Sensor resource not found.']);
});

test('update endpoint applies changes and reports missing sensors', function () {
    $connection = fleetopsApiSensorBoot();
    $connection->table('sensors')->insert([
        'uuid'         => 'sensor-1',
        'internal_id'  => 'SENSOR-001',
        'company_uuid' => 'company-1',
        'name'         => 'Temp',
    ]);

    $controller = new SensorController();
    $request    = UpdateSensorRequest::create('/v1/sensors/SENSOR-001', 'PUT', ['name' => 'Updated Temp']);

    expect($controller->update('SENSOR-001', $request))->toBeInstanceOf(SensorResource::class)
        ->and($connection->table('sensors')->value('name'))->toBe('Updated Temp');

    $missing = $controller->update('missing', UpdateSensorRequest::create('/x', 'PUT', ['name' => 'Nope']));
    expect($missing->getStatusCode())->toBe(404);
});

test('delete endpoint soft deletes the sensor and reports missing ones', function () {
    $connection = fleetopsApiSensorBoot();
    $connection->table('sensors')->insert([
        'uuid'         => 'sensor-1',
        'internal_id'  => 'SENSOR-001',
        'company_uuid' => 'company-1',
        'name'         => 'Temp',
    ]);

    $controller = new SensorController();

    expect($controller->delete('SENSOR-001'))->toBeInstanceOf(DeletedResource::class)
        ->and($connection->table('sensors')->whereNotNull('deleted_at')->count())->toBe(1);

    $missing = $controller->delete('missing');
    expect($missing->getStatusCode())->toBe(404);
});
