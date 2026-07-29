<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ZoneController;
use Fleetbase\FleetOps\Http\Requests\CreateZoneRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateZoneRequest;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the API ZoneController against SQLite: creating zones with borders
 * derived from latitude/longitude pairs and location inputs with service
 * area assignment, updating zone borders, and the protected helper seams
 * for radius parsing, border construction, record persistence, lookups,
 * resources and responses.
 */
class FleetOpsZoneControllerProbe extends ZoneController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsZoneWkbFromWkt(string $wkt): ?string
{
    $wkt        = trim($wkt);
    $packPoints = function (string $body): string {
        $points = array_map('trim', explode(',', $body));
        $out    = pack('V', count($points));
        foreach ($points as $pair) {
            [$lng, $lat] = array_map('floatval', preg_split('/\s+/', trim($pair)));
            $out .= pack('d', $lng) . pack('d', $lat);
        }

        return $out;
    };

    if (preg_match('/^POINT\(([^)]+)\)$/i', $wkt, $m)) {
        [$lng, $lat] = array_map('floatval', preg_split('/\s+/', trim($m[1])));

        return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
    }

    if (preg_match('/^POLYGON\((.+)\)$/is', $wkt, $m)) {
        preg_match_all('/\(([^()]+)\)/', $m[1], $rings);
        $body = pack('C', 1) . pack('V', 3) . pack('V', count($rings[1]));
        foreach ($rings[1] as $ring) {
            $body .= $packPoints($ring);
        }

        return pack('V', 0) . $body;
    }

    if (preg_match('/^MULTIPOLYGON\((.+)\)$/is', $wkt, $m)) {
        preg_match_all('/\(\(([^()]+(?:\),\([^()]+)*)\)\)/', $m[1], $polygons);
        $body = pack('C', 1) . pack('V', 6) . pack('V', count($polygons[1]));
        foreach ($polygons[1] as $polygon) {
            $rings = preg_split('/\),\(/', $polygon);
            $body .= pack('C', 1) . pack('V', 3) . pack('V', count($rings));
            foreach ($rings as $ring) {
                $body .= $packPoints($ring);
            }
        }

        return pack('V', 0) . $body;
    }

    return null;
}

function fleetopsZoneBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => fleetopsZoneWkbFromWkt((string) $wkt) ?? $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => fleetopsZoneWkbFromWkt((string) $wkt) ?? $wkt);
    $connection = new class($pdo) extends SQLiteConnection {
        public function prepareBindings(array $bindings)
        {
            // The sqlite grammar binds spatial objects as strings, so convert
            // geometries to parseable spatial WKB at bind time
            foreach ($bindings as $key => $value) {
                if ($value instanceof Fleetbase\LaravelMysqlSpatial\Types\Geometry) {
                    $bindings[$key] = fleetopsZoneWkbFromWkt($value->toWKT()) ?? (string) $value;
                }
            }

            return parent::prepareBindings($bindings);
        }
    };
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'zones'         => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'description', 'border', 'color', 'stroke_color', 'status', '_key'],
        'service_areas' => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status', 'border', '_key'],
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

    // The webhook lifecycle observer serializes zone locations through the
    // unavailable GEOS engine — detach post-persistence listeners while
    // keeping the creating-hooks that generate uuids
    new Zone();
    $dispatcher = EloquentModel::getEventDispatcher();
    foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
        $dispatcher->forget('eloquent.' . $event . ': ' . Zone::class);
    }

    return $connection;
}

test('creating zones derives borders from coordinates and locations', function () {
    $connection = fleetopsZoneBoot();
    $connection->table('service_areas')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'sa_zoneparent1', 'company_uuid' => 'company-1', 'name' => 'Parent Area']);
    $controller = new ZoneController();

    $fromCoords = $controller->create(CreateZoneRequest::create('/v1/zones', 'POST', [
        'name'         => 'Coord Zone',
        'latitude'     => 1.30,
        'longitude'    => 103.80,
        'radius'       => 250,
        'service_area' => 'sa_zoneparent1',
    ]));
    expect($connection->table('zones')->where('name', 'Coord Zone')->count())->toBe(1)
        ->and($connection->table('zones')->where('name', 'Coord Zone')->value('service_area_uuid'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('zones')->where('name', 'Coord Zone')->value('border'))->not->toBeNull();

    $fromLocation = $controller->create(CreateZoneRequest::create('/v1/zones', 'POST', [
        'name'     => 'Location Zone',
        'location' => ['lat' => 1.35, 'lng' => 103.85],
    ]));
    expect($connection->table('zones')->where('name', 'Location Zone')->count())->toBe(1)
        ->and($connection->table('zones')->where('name', 'Location Zone')->value('border'))->not->toBeNull();
});

test('updating zones rebuilds borders and missing ids respond not found', function () {
    $connection = fleetopsZoneBoot();
    $connection->table('zones')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'zone_updone1', 'company_uuid' => 'company-1', 'name' => 'Zone One']);

    $controller = new ZoneController();

    $updated = $controller->update('zone_updone1', UpdateZoneRequest::create('/v1/zones/zone_updone1', 'PUT', [
        'name'      => 'Zone One Updated',
        'latitude'  => 1.31,
        'longitude' => 103.81,
        'radius'    => 300,
    ]));
    expect($connection->table('zones')->value('name'))->toBe('Zone One Updated')
        ->and($connection->table('zones')->value('border'))->not->toBeNull();

    $missing = $controller->update('zone_missing', UpdateZoneRequest::create('/v1/zones/zone_missing', 'PUT', ['name' => 'X']));
    expect($missing->getStatusCode())->toBe(404);
});

test('helper seams parse radii build borders and wrap resources', function () {
    $connection = fleetopsZoneBoot();
    $connection->table('zones')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'zone_seams1', 'company_uuid' => 'company-1', 'name' => 'Seam Zone']);
    $probe = new FleetOpsZoneControllerProbe();

    expect($probe->callHelper('radiusFromRequest', Illuminate\Http\Request::create('/x', 'GET', ['radius' => '750'])))->toBe(750)
        ->and($probe->callHelper('radiusFromRequest', Illuminate\Http\Request::create('/x', 'GET')))->toBe(500)
        ->and($probe->callHelper('createBorderFromPoint', new Point(1.30, 103.80), 300))->not->toBeNull()
        ->and($probe->callHelper('pointFromLocation', ['lat' => 1.31, 'lng' => 103.81]))->toBeInstanceOf(Point::class)
        ->and($probe->callHelper('findZoneRecord', 'zone_seams1')?->uuid)->toBe('22222222-2222-4222-8222-222222222222');

    $created = $probe->callHelper('createZone', ['company_uuid' => 'company-1', 'name' => 'Seam Created']);
    expect($created)->toBeInstanceOf(Zone::class);

    expect($probe->callHelper('zoneResource', $created))->not->toBeNull()
        ->and($probe->callHelper('zoneResourceCollection', collect([$created])))->not->toBeNull()
        ->and($probe->callHelper('deletedZoneResource', $created))->not->toBeNull()
        ->and($probe->callHelper('jsonResponse', ['ok' => true], 200)->getData(true))->toBe(['ok' => true]);
});

test('zone centroids and location accessors resolve through injected engines', function () {
    $connection = fleetopsZoneBoot();
    Brick\Geo\Engine\GeometryEngineRegistry::set(new class(new PDO('sqlite::memory:'), false) extends Brick\Geo\Engine\PDOEngine {
        public function centroid(Brick\Geo\Geometry $g): Brick\Geo\Point
        {
            return Brick\Geo\Point::xy(1.32, 103.82);
        }
    });

    $connection->table('zones')->insert(['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'zone_centroid1', 'company_uuid' => 'company-1', 'name' => 'Centroid Zone', 'border' => fleetopsZoneWkbFromWkt('POLYGON((103.8 1.3,103.9 1.3,103.9 1.4,103.8 1.3))')]);
    $zone = Zone::where('uuid', '33333333-3333-4333-8333-333333333333')->first();

    // FleetOps builds brick geometries with x=latitude, y=longitude
    expect($zone->getCentroid()->x())->toBe(1.32)
        ->and($zone->location->getLat())->toBe(1.32)
        ->and($zone->latitude)->toBe(1.32)
        ->and($zone->longitude)->toBe(103.82);
});
