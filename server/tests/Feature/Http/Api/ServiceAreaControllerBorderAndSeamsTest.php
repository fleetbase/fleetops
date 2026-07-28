<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\logger')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function logger($message = null, array $context = []) { $log = \app(\'log\'); if ($message !== null) { $log->error($message, $context); } return $log; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceAreaController;
use Fleetbase\FleetOps\Http\Requests\CreateServiceAreaRequest;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the API ServiceAreaController against SQLite: creating service
 * areas with borders derived from latitude/longitude pairs and mixed
 * location inputs with parent resolution, and the helper seams for border
 * construction, uuid lookup, point parsing, record persistence, queries,
 * resources and error responses.
 */
class FleetOpsServiceAreaControllerProbe extends ServiceAreaController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsServiceAreaBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
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

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    app()->instance('log', new class {
        public array $entries = [];

        public function error($message, array $context = [])
        {
            $this->entries[] = $message;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    $GLOBALS['fleetopsServiceAreaLog'] = app('log');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'service_areas' => ['uuid', 'public_id', 'company_uuid', 'parent_uuid', 'name', 'type', 'status', 'country', 'border', 'color', 'stroke_color', 'trigger_on_entry', 'trigger_on_exit', 'dwell_threshold_minutes', 'speed_limit_kmh', '_key'],
        'zones'         => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'border'],
        'directives'    => ['uuid', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'],
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

    session(['company' => 'company-1']);

    return $connection;
}

test('creating service areas derives borders from coordinates and locations', function () {
    $connection = fleetopsServiceAreaBoot();
    $connection->table('service_areas')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'sa_parentone11', 'company_uuid' => 'company-1', 'name' => 'Parent']);

    $controller = new ServiceAreaController();

    $fromCoords = $controller->create(CreateServiceAreaRequest::create('/v1/service-areas', 'POST', [
        'name'      => 'Coord Area',
        'latitude'  => 1.30,
        'longitude' => 103.80,
        'radius'    => 250,
        'parent'    => 'sa_parentone11',
    ]));
    expect($connection->table('service_areas')->where('name', 'Coord Area')->count())->toBe(1)
        ->and($connection->table('service_areas')->where('name', 'Coord Area')->value('parent_uuid'))->toBe('11111111-1111-4111-8111-111111111111');

    $fromLocation = $controller->create(CreateServiceAreaRequest::create('/v1/service-areas', 'POST', [
        'name'     => 'Location Area',
        'location' => ['lat' => 1.35, 'lng' => 103.85],
    ]));
    expect($connection->table('service_areas')->where('name', 'Location Area')->count())->toBe(1)
        ->and($connection->table('service_areas')->where('name', 'Location Area')->value('border'))->not->toBeNull();
});

test('helper seams build borders resolve records and wrap responses', function () {
    $connection = fleetopsServiceAreaBoot();
    $connection->table('service_areas')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'sa_seamsone11', 'company_uuid' => 'company-1', 'name' => 'Existing']);

    $probe = new FleetOpsServiceAreaControllerProbe();

    $border = $probe->callHelper('createBorderFromPoint', new Point(1.30, 103.80), 300);
    expect($border)->toBeInstanceOf(MultiPolygon::class);

    expect($probe->callHelper('serviceAreaUuid', 'sa_seamsone11', ['public_id' => 'sa_seamsone11', 'company_uuid' => 'company-1']))->toBe('11111111-1111-4111-8111-111111111111')
        ->and($probe->callHelper('pointFromLocation', ['lat' => 1.31, 'lng' => 103.81]))->toBeInstanceOf(Point::class)
        ->and($probe->callHelper('findServiceAreaRecord', 'sa_seamsone11')?->uuid)->toBe('11111111-1111-4111-8111-111111111111');

    $created = $probe->callHelper('createServiceArea', ['company_uuid' => 'company-1', 'name' => 'Seam Area', 'status' => 'active']);
    expect($created)->toBeInstanceOf(ServiceArea::class);

    expect($probe->callHelper('serviceAreaResource', $created))->not->toBeNull()
        ->and($probe->callHelper('serviceAreaResourceCollection', collect([$created])))->not->toBeNull()
        ->and($probe->callHelper('deletedServiceAreaResource', $created))->not->toBeNull()
        ->and($probe->callHelper('jsonResponse', ['ok' => true], 200)->getData(true))->toBe(['ok' => true]);

    $probe->callHelper('logServiceAreaCreateFailure', new RuntimeException('boom'));
    expect($GLOBALS['fleetopsServiceAreaLog']->entries)->not->toBeEmpty();
});
