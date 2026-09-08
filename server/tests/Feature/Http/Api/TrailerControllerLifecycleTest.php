<?php

use Fleetbase\FleetOps\Exports\TrailerExport;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\EquipmentController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrailerController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\VehicleController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\TrailerController as InternalTrailerController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VehicleController as InternalVehicleController;
use Fleetbase\FleetOps\Http\Requests\CreateTrailerRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateTrailerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Imports\TrailerImport;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('Fleetbase\\Support\\auth')) {
    eval('namespace Fleetbase\\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\abort')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1; function abort($code, $message = "", array $headers = []) { throw new \\Symfony\\Component\\HttpKernel\\Exception\\HttpException($code, $message, null, $headers); }');
}

if (!function_exists('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\abort')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1; function abort($code, $message = "", array $headers = []) { throw new \\Symfony\\Component\\HttpKernel\\Exception\\HttpException($code, $message, null, $headers); }');
}

if (!function_exists('Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1; function broadcast($event) { return $event; }');
}

if (!class_exists('Fleetbase\\Http\\Requests\\ExportRequest', false)) {
    eval('namespace Fleetbase\\Http\\Requests; class ExportRequest extends \\Illuminate\\Http\\Request {}');
}

if (!class_exists('Fleetbase\\Http\\Requests\\ImportRequest', false)) {
    eval('namespace Fleetbase\\Http\\Requests; class ImportRequest extends \\Illuminate\\Http\\Request {}');
}

class FleetOpsTrailerControllerProbe extends TrailerController
{
    public function mappedInput(Request $request): array
    {
        return $this->input($request);
    }

    protected function queryTrailers(Request $request, callable $callback)
    {
        $query = Trailer::where('company_uuid', session('company'));
        $callback($query, $request);

        return $query->get();
    }
}

class FleetOpsInternalTrailerSpreadsheetProbe extends InternalTrailerController
{
    public array $downloads = [];
    public array $imports   = [];

    protected function downloadExport(TrailerExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['file' => $fileName];
    }

    protected function createImport(): TrailerImport
    {
        $import           = new TrailerImport();
        $import->imported = 2;

        return $import;
    }

    protected function importFile(TrailerImport $import, string $path, string $disk): void
    {
        $this->imports[] = [$path, $disk];
    }
}

class FleetOpsTrailerExportRequestFake extends ExportRequest
{
    public function array($key = null, $default = [])
    {
        return (array) $this->input($key, $default);
    }
}

class FleetOpsTrailerImportRequestFake extends ImportRequest
{
    public array $resolvedFiles = [];

    public function resolveFilesFromIds(string $param = 'files')
    {
        return collect($this->resolvedFiles);
    }
}

function fleetOpsTrailerControllerDatabase(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', function ($wkt, $srid = 0, $axisOrder = null) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $longitude, $latitude) === 2) {
            return pack('V', (int) $srid) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
        }

        return $wkt;
    });
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Dispatcher());
    // Model hooks bind to the dispatcher present when a class boots; re-boot so the
    // hooks of models booted by earlier test files land on this dispatcher.
    EloquentModel::clearBootedModels();
    $config = new Repository([
        'activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default'],
        'api'         => ['cache' => ['enabled' => false]],
        'filesystems' => ['default' => 'local'],
    ]);
    app()->instance('config', $config);
    app()->instance(Illuminate\Contracts\Config\Repository::class, $config);
    app()->instance(Spatie\Activitylog\CauserResolver::class, new class extends Spatie\Activitylog\CauserResolver {
        public function __construct()
        {
        }

        public function resolve(EloquentModel|int|string|null $subject = null): ?EloquentModel
        {
            return null;
        }
    });
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $connection)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->connection;
        }

        public function __call($method, $arguments)
        {
            return $this->connection->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('db.schema', $connection->getSchemaBuilder());
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    app()->instance('request', Request::create('/'));

    $schema = $connection->getSchemaBuilder();
    $schema->create('assets', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'category_uuid', 'vendor_uuid', 'warranty_uuid', 'telematic_uuid', 'current_place_uuid', 'photo_uuid', 'name', 'description', 'code', 'type', 'body_type', 'asset_class', 'status', 'vin', 'plate_number', 'serial_number', 'make', 'model', 'year', 'color', 'usage_type', 'measurement_system', 'odometer', 'odometer_unit', 'ownership_type', 'purchased_at', 'lease_expires_at', 'financing_status', 'currency', 'acquisition_cost', 'current_value', 'insurance_value', 'depreciation_rate', 'length', 'width', 'height', 'tare_weight', 'gvwr', 'payload_capacity', 'cargo_volume', 'axle_count', 'tire_count', 'door_count', 'coupling_type', 'brake_type', 'abs_equipped', 'ebs_equipped', 'refrigerated', 'temperature_min', 'temperature_max', 'reefer_engine_hours', 'capacity', 'specs', 'attributes', 'notes', 'location', 'speed', 'heading', 'altitude', 'online', 'last_online_at', 'telematics', 'slug', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('vehicles', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'display_name', 'plate_number', 'status', 'slug', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'email', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('asset_connections', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'connector_type', 'connector_uuid', 'connected_type', 'connected_uuid', 'active_connected_uuid', 'active_connector_position', 'relationship_type', 'position', 'connected_at', 'disconnected_at', 'source', 'confidence', 'notes', 'meta', 'created_by_uuid', 'updated_by_uuid', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    foreach (['categories', 'vendors', 'warranties', 'files'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'name', 'type', 'path', 'disk', 'status', '_key'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    foreach (['devices', 'equipments'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'warranty_uuid', 'photo_uuid', 'name', 'attachable_type', 'attachable_uuid', 'equipable_type', 'equipable_uuid', 'status', '_key'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('custom_field_values', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('custom_fields', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'label', 'name', 'type'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('maintenances', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'status', 'completed_at'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('maintenance_schedules', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'subject_type', 'subject_uuid', 'status', 'next_due_at'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('activity_log', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'event', 'batch_uuid'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
    });
    $schema->create('positions', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'subject_type', 'subject_uuid', 'destination_uuid', 'coordinates', 'heading', 'bearing', 'speed', 'altitude', '_key'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });

    session(['company' => 'company-trailer-api', 'user' => 'user-trailer-api']);

    return $connection;
}

function fleetOpsSeedTrailerApi(SQLiteConnection $connection): void
{
    $connection->table('assets')->insert([
        ['uuid' => 'trailer-api-1', 'public_id' => 'trailer_api_one', 'company_uuid' => 'company-trailer-api', 'name' => 'Trailer One', 'asset_class' => 'trailer', 'status' => 'available'],
        ['uuid' => 'trailer-api-2', 'public_id' => 'trailer_api_two', 'company_uuid' => 'company-trailer-api', 'name' => 'Trailer Two', 'asset_class' => 'trailer', 'status' => 'available'],
        ['uuid' => 'trailer-other', 'public_id' => 'trailer_other', 'company_uuid' => 'company-other', 'name' => 'Other Trailer', 'asset_class' => 'trailer', 'status' => 'available'],
    ]);
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-api-1', 'public_id' => 'vehicle_api_one', 'internal_id' => 'TRUCK-1', 'company_uuid' => 'company-trailer-api', 'name' => 'Truck One'],
        ['uuid' => 'vehicle-api-2', 'public_id' => 'vehicle_api_two', 'internal_id' => 'TRUCK-2', 'company_uuid' => 'company-trailer-api', 'name' => 'Truck Two'],
    ]);
}

function fleetOpsCreateTrailerApiRequest(array $input): CreateTrailerRequest
{
    return CreateTrailerRequest::create('/v1/trailers', 'POST', $input);
}

function fleetOpsUpdateTrailerApiRequest(array $input): UpdateTrailerRequest
{
    return UpdateTrailerRequest::create('/v1/trailers/trailer_api_one', 'PATCH', $input);
}

test('public trailer controller creates queries finds updates and deletes company trailers', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $controller = new FleetOpsTrailerControllerProbe();

    $created = $controller->create(fleetOpsCreateTrailerApiRequest(['name' => 'Created Trailer', 'type' => 'flatbed']));
    expect($created)->not->toBeNull()
        ->and($connection->table('assets')->where('name', 'Created Trailer')->value('asset_class'))->toBe('trailer');

    $query = $controller->query(Request::create('/v1/trailers', 'GET'));
    expect($query->count())->toBe(3)
        ->and($controller->find('trailer_api_one'))->not->toBeNull()
        ->and($controller->find('missing')->getStatusCode())->toBe(404);

    $updated = $controller->update('trailer_api_one', fleetOpsUpdateTrailerApiRequest(['name' => 'Renamed Trailer']));
    expect($updated)->not->toBeNull()
        ->and($connection->table('assets')->where('uuid', 'trailer-api-1')->value('name'))->toBe('Renamed Trailer')
        ->and($controller->update('missing', fleetOpsUpdateTrailerApiRequest(['name' => 'Missing']))->getStatusCode())->toBe(404)
        ->and($controller->delete('missing')->getStatusCode())->toBe(404);

    $deleted = $controller->delete('trailer_api_two');
    expect($deleted)->not->toBeNull()
        ->and($connection->table('assets')->where('uuid', 'trailer-api-2')->whereNull('deleted_at')->count())->toBe(0);
});

test('public trailer input accepts coordinate forms and clears public relations', function () {
    fleetOpsTrailerControllerDatabase();
    $controller  = new FleetOpsTrailerControllerProbe();
    $coordinates = $controller->mappedInput(Request::create('/v1/trailers', 'POST', ['name' => 'Located', 'latitude' => 1.3, 'longitude' => 103.8]));
    $point       = $controller->mappedInput(Request::create('/v1/trailers', 'POST', ['name' => 'Located', 'location' => [103.8, 1.3]]));
    $cleared     = $controller->mappedInput(Request::create('/v1/trailers', 'POST', ['name' => 'No Relations', 'category' => '', 'vendor' => '', 'warranty' => '', 'photo' => '']));
    expect($coordinates['location'])->not->toBeNull()
        ->and($point['location'])->not->toBeNull()
        ->and($cleared)->toMatchArray(['category_uuid' => null, 'vendor_uuid' => null, 'warranty_uuid' => null, 'photo_uuid' => null]);
});

test('public trailer tracking persists positions and rejects stale and missing events', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $controller = new TrailerController();
    Carbon::setTestNow('2026-09-03 12:00:00');

    $tracked = $controller->track('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/track', 'POST', ['latitude' => 1.3, 'longitude' => 103.8, 'speed' => 40, 'heading' => 180, 'altitude' => 10, 'odometer' => 1200, 'observed_at' => '2026-09-03 12:00:00']));
    expect($tracked)->not->toBeNull()
        ->and($connection->table('positions')->count())->toBe(1);

    $connection->table('assets')->where('uuid', 'trailer-api-1')->update(['telematics' => json_encode(['last_event_at' => '2026-09-03T12:00:00.000000Z'])]);
    $controller->track('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/track', 'POST', ['latitude' => 1.2, 'longitude' => 103.7, 'observed_at' => '2026-09-03 11:00:00']));
    expect($connection->table('positions')->count())->toBe(1)
        ->and($controller->track('missing', Request::create('/v1/trailers/missing/track', 'POST', ['latitude' => 1, 'longitude' => 2]))->getStatusCode())->toBe(404);
    Carbon::setTestNow();
});

test('public trailer towing lifecycle is idempotent conflict safe and exposes history', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $controller = new TrailerController();

    $attached = $controller->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'vehicle_api_one', 'position' => 1, 'source' => 'dispatcher']));
    expect($attached)->not->toBeNull()
        ->and($connection->table('asset_connections')->count())->toBe(1)
        ->and($controller->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'vehicle_api_one', 'position' => 1])))->not->toBeNull()
        ->and($connection->table('asset_connections')->count())->toBe(1);

    expect($controller->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'vehicle_api_two']))->getStatusCode())->toBe(409)
        ->and($controller->delete('trailer_api_one')->getStatusCode())->toBe(409)
        ->and($controller->connections('trailer_api_one')->count())->toBe(1)
        ->and($controller->vehicleTrailers('vehicle_api_one')->count())->toBe(1)
        ->and($controller->connections('missing')->getStatusCode())->toBe(404)
        ->and($controller->vehicleTrailers('missing')->getStatusCode())->toBe(404);

    expect($controller->detach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/detach', 'POST', ['notes' => 'Arrived']))->getStatusCode())->toBe(200)
        ->and($controller->detach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/detach', 'POST'))->getStatusCode())->toBe(200)
        ->and($controller->detach('missing', Request::create('/v1/trailers/missing/detach', 'POST'))->getStatusCode())->toBe(404);

    $controller->attach('trailer_api_two', Request::create('/v1/trailers/trailer_api_two/attach', 'POST', ['vehicle' => 'vehicle_api_one', 'position' => 1]));
    expect($controller->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'vehicle_api_one', 'position' => 1]))->getStatusCode())->toBe(409)
        ->and($controller->attach('missing', Request::create('/v1/trailers/missing/attach', 'POST', ['vehicle' => 'vehicle_api_one']))->getStatusCode())->toBe(404)
        ->and($controller->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'missing']))->getStatusCode())->toBe(404);
});

class FleetOpsInternalTrailerAfterSaveFake extends Trailer
{
    public array $syncedValues = [];

    public function syncCustomFieldValues(array $values, array $options = []): array
    {
        $this->syncedValues[] = $values;

        return $values;
    }
}

class FleetOpsInternalTrailerDeleteProbe extends InternalTrailerController
{
    public array $deleted = [];

    protected function deleteTrailerRecord($id, Request $request)
    {
        $this->deleted[] = $id;

        return response()->json(['status' => 'deleted']);
    }
}

class FleetOpsVehicleExpansionProbe extends VehicleController
{
    public function mapExpansions(Request $request): array
    {
        return $this->resolvePublicExpansions($request, static::EXPANDABLE);
    }
}

test('internal trailer controller syncs custom fields and manages towing', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $controller = new InternalTrailerController();
    $fake       = new FleetOpsInternalTrailerAfterSaveFake();
    $controller->afterSave(Request::create('/int/v1/trailers', 'POST', ['trailer' => ['custom_field_values' => ['door' => 'sealed']]]), $fake);
    $controller->afterSave(Request::create('/int/v1/trailers', 'POST'), $fake);
    expect($fake->syncedValues)->toBe([['door' => 'sealed']]);

    expect($controller->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'vehicle-api-1', 'position' => 1]), 'trailer-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('asset_connections')->count())->toBe(1)
        ->and($controller->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'vehicle-api-1', 'position' => 1]), 'trailer-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('asset_connections')->count())->toBe(1)
        ->and($controller->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'vehicle-api-2']), 'trailer-api-1')->getStatusCode())->toBe(409)
        ->and($controller->attach(Request::create('/int/v1/trailers/missing/attach', 'POST', ['vehicle' => 'vehicle-api-1']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'missing']), 'trailer-api-1')->getStatusCode())->toBe(404);

    expect($controller->detach('trailer-api-1')->getStatusCode())->toBe(200)
        ->and($controller->detach('trailer-api-1')->getStatusCode())->toBe(200)
        ->and($controller->detach('missing')->getStatusCode())->toBe(404);
    $controller->attach(Request::create('/int/v1/trailers/trailer-api-2/attach', 'POST', ['vehicle' => 'vehicle-api-1', 'position' => 1]), 'trailer-api-2');
    expect($controller->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'vehicle-api-1', 'position' => 1]), 'trailer-api-1')->getStatusCode())->toBe(409);
});

test('internal trailer controller attaches and detaches equipment with ownership guards', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $connection->table('equipments')->insert([
        ['uuid' => 'equipment-api-1', 'public_id' => 'equipment_api_one', 'company_uuid' => 'company-trailer-api', 'name' => 'Lift Gate'],
        ['uuid' => 'equipment-api-2', 'public_id' => 'equipment_api_two', 'company_uuid' => 'company-trailer-api', 'name' => 'Pump'],
    ]);
    $controller = new InternalTrailerController();
    expect($controller->attachEquipment(Request::create('/attach', 'POST', ['equipment' => 'equipment_api_one']), 'trailer-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('equipments')->where('uuid', 'equipment-api-1')->value('equipable_uuid'))->toBe('trailer-api-1')
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'equipment_api_two']), 'trailer-api-1')->getStatusCode())->toBe(422)
        ->and($controller->attachEquipment(Request::create('/attach', 'POST', ['equipment' => 'equipment_api_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->attachEquipment(Request::create('/attach', 'POST', ['equipment' => 'missing']), 'trailer-api-1')->getStatusCode())->toBe(404)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'equipment_api_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'missing']), 'trailer-api-1')->getStatusCode())->toBe(404)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'equipment_api_one']), 'trailer-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('equipments')->where('uuid', 'equipment-api-1')->value('equipable_uuid'))->toBeNull();
});

test('internal trailer controller attaches and detaches devices with ownership guards', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $connection->table('devices')->insert([
        ['uuid' => 'device-api-1', 'public_id' => 'device_api_one', 'company_uuid' => 'company-trailer-api', 'name' => 'Tracker', 'attachable_type' => null, 'attachable_uuid' => null],
        ['uuid' => 'device-api-2', 'public_id' => 'device_api_two', 'company_uuid' => 'company-trailer-api', 'name' => 'Other Tracker', 'attachable_type' => null, 'attachable_uuid' => null],
        // Already installed on Trailer Two: attaching it to Trailer One must be refused, not re-homed.
        ['uuid' => 'device-api-3', 'public_id' => 'device_api_three', 'company_uuid' => 'company-trailer-api', 'name' => 'Installed Tracker', 'attachable_type' => 'Fleetbase\\FleetOps\\Models\\Trailer', 'attachable_uuid' => 'trailer-api-2'],
    ]);
    app()->forgetInstance('db.schema');
    $controller = new InternalTrailerController();
    $conflict   = $controller->attachDevice(Request::create('/attach', 'POST', ['device' => 'device_api_three']), 'trailer-api-1');
    expect($conflict->getStatusCode())->toBe(409)
        ->and($conflict->getData(true)['errors'][0] ?? $conflict->getData(true)['error'] ?? null)->toBe('Device is already attached to Trailer Two. Detach it before attaching it elsewhere.')
        ->and($connection->table('devices')->where('uuid', 'device-api-3')->value('attachable_uuid'))->toBe('trailer-api-2');
    expect($controller->attachDevice(Request::create('/attach', 'POST', ['device' => 'device_api_one']), 'trailer-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('devices')->where('uuid', 'device-api-1')->value('attachable_uuid'))->toBe('trailer-api-1')
        ->and($controller->detachDevice(Request::create('/detach', 'POST', ['device' => 'device_api_two']), 'trailer-api-1')->getStatusCode())->toBe(422)
        ->and($controller->attachDevice(Request::create('/attach', 'POST', ['device' => 'device_api_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->attachDevice(Request::create('/attach', 'POST', ['device' => 'missing']), 'trailer-api-1')->getStatusCode())->toBe(404)
        ->and($controller->detachDevice(Request::create('/detach', 'POST', ['device' => 'device_api_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->detachDevice(Request::create('/detach', 'POST', ['device' => 'missing']), 'trailer-api-1')->getStatusCode())->toBe(404)
        ->and($controller->detachDevice(Request::create('/detach', 'POST', ['device' => 'device_api_one']), 'trailer-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('devices')->where('uuid', 'device-api-1')->value('attachable_uuid'))->toBeNull();
});

test('public equipment attachment endpoints support trailers, idempotence, detach and missing resources', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $connection->table('equipments')->insert(['uuid' => 'equipment-public-1', 'public_id' => 'equipment_public_one', 'company_uuid' => 'company-trailer-api', 'name' => 'Trailer Pump']);
    $controller = new EquipmentController();

    expect($controller->attach(Request::create('/v1/equipment/equipment_public_one/attach', 'POST', ['attachable_type' => 'trailer', 'attachable' => 'trailer_api_one']), 'equipment_public_one'))->not->toBeNull()
        ->and($connection->table('equipments')->where('uuid', 'equipment-public-1')->value('equipable_uuid'))->toBe('trailer-api-1')
        ->and($controller->attach(Request::create('/v1/equipment/equipment_public_one/attach', 'POST', ['attachable_type' => 'trailer', 'attachable' => 'trailer_api_one']), 'equipment_public_one'))->not->toBeNull()
        ->and($controller->attach(Request::create('/v1/equipment/missing/attach', 'POST', ['attachable_type' => 'trailer', 'attachable' => 'trailer_api_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->detach('equipment_public_one'))->not->toBeNull()
        ->and($connection->table('equipments')->where('uuid', 'equipment-public-1')->value('equipable_uuid'))->toBeNull()
        ->and($controller->detach('equipment_public_one'))->not->toBeNull()
        ->and($controller->detach('missing')->getStatusCode())->toBe(404);
});

test('internal trailer spreadsheet endpoints scope exports and count imported files', function () {
    $controller    = new FleetOpsInternalTrailerSpreadsheetProbe();
    $exportRequest = FleetOpsTrailerExportRequestFake::create('/int/v1/trailers/export', 'POST', ['format' => 'csv', 'selections' => ['trailer-api-1']]);
    $download      = $controller->export($exportRequest);
    expect($download['file'])->toEndWith('.csv')
        ->and($controller->downloads[0][0])->toBeInstanceOf(TrailerExport::class);

    $importRequest                = FleetOpsTrailerImportRequestFake::create('/int/v1/trailers/import', 'POST', ['disk' => 'local']);
    $importRequest->resolvedFiles = [(object) ['path' => 'trailers-one.csv'], (object) ['path' => 'trailers-two.csv']];
    $response                     = $controller->import($importRequest);
    expect($response->getData(true)['imported'])->toBe(4)
        ->and($controller->imports)->toBe([['trailers-one.csv', 'local'], ['trailers-two.csv', 'local']]);
});

test('public trailer responses expose relation identifiers, expansions and sanitized telematics', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $connection->table('vendors')->insert(['uuid' => 'vendor-api-1', 'public_id' => 'vendor_api_one', 'company_uuid' => 'company-trailer-api', 'name' => 'Trailer Vendor']);
    $connection->table('assets')->where('uuid', 'trailer-api-1')->update([
        'vendor_uuid' => 'vendor-api-1',
        'telematics'  => json_encode(['last_provider' => 'flespi', 'last_device_uuid' => 'device-uuid', 'last_event_uuid' => 'event-uuid', 'last_event_at' => '2026-09-01T00:00:00Z']),
    ]);
    $controller = new FleetOpsTrailerControllerProbe();

    // No request injected: the controller falls back to the container request.
    app()->instance('request', Request::create('/v1/trailers/trailer_api_one', 'GET'));
    $plain = $controller->find('trailer_api_one')->resolve();
    expect($plain['id'])->toBe('trailer_api_one')
        ->and($plain['vendor_id'])->toBe('vendor_api_one')
        ->and($plain['category_id'])->toBeNull()
        ->and($plain['vehicle_id'])->toBeNull()
        ->and($plain['current_vehicle'])->toBeNull()
        ->and($plain)->not->toHaveKeys(['uuid', 'company_uuid', 'vendor', 'vendor_uuid', 'equipments'])
        ->and((array) $plain['telematics'])->toBe(['last_provider' => 'flespi', 'last_event_at' => '2026-09-01T00:00:00Z'])
        ->and($plain['equipment']->resolve())->toBe([])
        ->and($plain['devices_count'])->toBe(0);

    $withRequest = Request::create('/v1/trailers/trailer_api_one', 'GET', ['with' => 'vendor,category,not_a_relation']);
    $expanded    = $controller->find('trailer_api_one', $withRequest)->resolve($withRequest);
    expect($expanded['vendor_id'])->toBe('vendor_api_one')
        ->and($expanded['vendor'])->not->toBeNull()
        ->and($expanded['category'])->toBeNull();

    $controller->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'TRUCK-1']));
    $attached = $controller->find('trailer_api_one', Request::create('/v1/trailers/trailer_api_one', 'GET'))->resolve();
    expect($attached['vehicle_id'])->toBe('vehicle_api_one')
        ->and($attached['current_vehicle'])->toBe(['id' => 'vehicle_api_one', 'name' => 'Truck One', 'plate_number' => null])
        ->and($attached['attachment_state'])->toBe('attached');
    $connectionBody = $attached['current_connection']->resolve();
    expect($connectionBody['vehicle_id'])->toBe('vehicle_api_one')
        ->and($connectionBody['trailer_id'])->toBe('trailer_api_one')
        ->and($connectionBody)->not->toHaveKeys(['uuid', 'connector_uuid', 'connected_uuid']);
});

test('public trailer tracking ignores null island observations', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    app()->instance('request', Request::create('/'));
    $controller = new FleetOpsTrailerControllerProbe();

    expect($controller->track('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/track', 'POST', ['latitude' => 0, 'longitude' => 0])))->not->toBeNull()
        ->and($connection->table('positions')->count())->toBe(0)
        ->and($connection->table('assets')->where('uuid', 'trailer-api-1')->value('last_online_at'))->toBeNull();
});

test('public vehicle responses expand currently attached trailers', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    (new FleetOpsTrailerControllerProbe())->attach('trailer_api_one', Request::create('/v1/trailers/trailer_api_one/attach', 'POST', ['vehicle' => 'vehicle_api_one']));

    $request = Request::create('/v1/vehicles/vehicle_api_one', 'GET', ['with' => 'trailers,driver']);
    app()->instance('request', $request);
    $mapped = (new FleetOpsVehicleExpansionProbe())->mapExpansions($request);
    expect($mapped)->toBe(['currentTrailers', 'driver']);

    $request->merge(['with' => $mapped]);
    $vehicle  = Vehicle::where('uuid', 'vehicle-api-1')->firstOrFail();
    $resolved = (new VehicleResource($vehicle))->toArray($request);
    expect($resolved['trailers']->resolve()[0]['id'])->toBe('trailer_api_one');
});

test('internal trailer controller refuses to delete an attached trailer and delegates otherwise', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $controller = new FleetOpsInternalTrailerDeleteProbe();
    $controller->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'vehicle-api-1']), 'trailer-api-1');
    $request = Request::create('/int/v1/trailers/trailer-api-1', 'DELETE');

    expect($controller->deleteRecord('trailer-api-1', $request)->getStatusCode())->toBe(409)
        ->and($controller->deleted)->toBe([]);

    $controller->detach('trailer-api-1');
    expect($controller->deleteRecord('trailer-api-1', $request)->getStatusCode())->toBe(200)
        ->and($controller->deleteRecord('missing', $request)->getStatusCode())->toBe(200)
        ->and($controller->deleted)->toBe(['trailer-api-1', 'missing']);
});

test('trailer bulk removal skips attached trailers and refuses fully attached selections', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    (new InternalTrailerController())->attach(Request::create('/int/v1/trailers/trailer-api-1/attach', 'POST', ['vehicle' => 'vehicle-api-1']), 'trailer-api-1');

    expect(fn () => (new Trailer())->bulkRemove(['trailer-api-1', 'trailer_api_one']))->toThrow(RuntimeException::class)
        ->and((new Trailer())->bulkRemove(['trailer-api-1', 'trailer_api_two', 'trailer-other']))->toBe(1)
        ->and($connection->table('assets')->where('uuid', 'trailer-api-2')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and($connection->table('assets')->where('uuid', 'trailer-api-1')->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($connection->table('assets')->where('uuid', 'trailer-other')->whereNull('deleted_at')->exists())->toBeTrue();
});

test('internal trailer controller exposes lifecycle statuses and types for console filters', function () {
    fleetOpsTrailerControllerDatabase();
    $controller = new InternalTrailerController();

    expect($controller->statuses()->getData(true))->toBe(Trailer::STATUSES)
        ->and($controller->types()->getData(true))->toBe(Trailer::TYPES);
});

test('internal vehicle controller attaches and detaches equipment with ownership guards', function () {
    $connection = fleetOpsTrailerControllerDatabase();
    fleetOpsSeedTrailerApi($connection);
    $connection->table('equipments')->insert([
        ['uuid' => 'equipment-vehicle-1', 'public_id' => 'equipment_vehicle_one', 'company_uuid' => 'company-trailer-api', 'name' => 'Lift Gate'],
        ['uuid' => 'equipment-vehicle-2', 'public_id' => 'equipment_vehicle_two', 'company_uuid' => 'company-trailer-api', 'name' => 'Pump'],
    ]);
    $controller = new InternalVehicleController();

    expect($controller->attachEquipment(Request::create('/attach', 'POST', ['equipment' => 'equipment_vehicle_one']), 'vehicle-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('equipments')->where('uuid', 'equipment-vehicle-1')->value('equipable_uuid'))->toBe('vehicle-api-1')
        ->and($controller->attachEquipment(Request::create('/attach', 'POST', ['equipment' => 'equipment_vehicle_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->attachEquipment(Request::create('/attach', 'POST', ['equipment' => 'missing']), 'vehicle-api-1')->getStatusCode())->toBe(404)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'equipment_vehicle_two']), 'vehicle-api-1')->getStatusCode())->toBe(422)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'equipment_vehicle_one']), 'missing')->getStatusCode())->toBe(404)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'missing']), 'vehicle-api-1')->getStatusCode())->toBe(404)
        ->and($controller->detachEquipment(Request::create('/detach', 'POST', ['equipment' => 'equipment_vehicle_one']), 'vehicle-api-1')->getStatusCode())->toBe(200)
        ->and($connection->table('equipments')->where('uuid', 'equipment-vehicle-1')->value('equipable_uuid'))->toBeNull();
});
