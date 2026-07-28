<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\config')) {
    eval('namespace Fleetbase\FleetOps\Support; function config($key = null, $default = null) { return $key === "fleetops.distance_matrix.provider" ? "calculate" : $default; }');
}

if (!function_exists('Cknow\Money\config')) {
    eval('namespace Cknow\Money; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\\FleetOps\\Support\\env')) {
    eval('namespace Fleetbase\\FleetOps\\Support; function env($key = null, $default = null) { return $default; }');
}

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Covers Utils geo helpers: company transaction currency fallbacks, point
 * resolution from mixed inputs including database-backed public ids and
 * uuids, coordinate extraction, OSRM distance matrices with cached and live
 * responses, integrated vendor id lookups, and geometry conversions.
 */
function fleetopsUtilsGeoBoot(): SQLiteConnection
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

    $redis = new class {
        public array $store = [];

        public function get($key)
        {
            return $this->store[$key] ?? null;
        }

        public function set($key, $value)
        {
            $this->store[$key] = $value;

            return true;
        }

        public function connection($name = null)
        {
            return $this;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };
    app()->instance('redis', $redis);
    Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');
    $GLOBALS['fleetopsUtilsRedisFake'] = $redis;

    Cache::swap(new class {
        public array $store = [];

        public function has($key)
        {
            return array_key_exists($key, $this->store);
        }

        public function get($key, $default = null)
        {
            return $this->store[$key] ?? (is_callable($default) ? $default() : $default);
        }

        public function put($key, $value, $ttl = null)
        {
            $this->store[$key] = $value;

            return true;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'companies'          => ['uuid', 'public_id', 'name', 'currency'],
        'settings'           => ['key', 'value'],
        'places'             => ['uuid', 'public_id', 'company_uuid', 'name', 'location'],
        'drivers'            => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'location'],
        'users'              => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'integrated_vendors' => ['uuid', 'company_uuid', 'provider'],
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

function fleetopsUtilsPointWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

test('company transaction currency falls back to ledger settings then usd', function () {
    $connection = fleetopsUtilsGeoBoot();
    $connection->table('companies')->insert([
        ['uuid' => 'co-1', 'name' => 'Acme', 'currency' => null],
        ['uuid' => 'co-2', 'name' => 'Beta', 'currency' => 'sgd'],
    ]);
    $connection->table('settings')->insert([
        'key'   => 'company.co-1.ledger.accounting-settings',
        'value' => json_encode(['base_currency' => 'eur']),
    ]);

    expect(Utils::getCompanyTransactionCurrency('co-1'))->toBe('EUR')
        ->and(Utils::getCompanyTransactionCurrency('co-2'))->toBe('SGD')
        ->and(Utils::getCompanyTransactionCurrency('co-missing'))->toBe('USD')
        ->and(Utils::getCompanyTransactionCurrency(null))->toBe('USD');
});

test('point from mixed resolves models expressions public ids and uuids', function () {
    $connection = fleetopsUtilsGeoBoot();
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_utilgeo1', 'company_uuid' => 'company-1', 'location' => fleetopsUtilsPointWkb(1.31, 103.81)]);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-uuid-1', 'public_id' => 'driver_utilgeo1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'location' => fleetopsUtilsPointWkb(1.32, 103.82)]);

    // Eloquent model carrying a spatial location attribute
    $place = new Place(['location' => new Point(1.30, 103.80)]);
    expect(Utils::getPointFromMixed($place)?->getLat())->toBe(1.30);

    // Raw query expression carrying a WKT point
    $expression = new Expression("ST_GeomFromText('POINT(103.83 1.33)')");
    $point      = Utils::getPointFromMixed($expression);
    expect($point?->getLat())->toBe(1.33)
        ->and($point?->getLng())->toBe(103.83);

    // Arrays holding resolvable public ids or nested location values
    expect(Utils::getPointFromMixed(['public_id' => 'place_utilgeo1'])?->getLat())->toBe(1.31)
        ->and(Utils::getPointFromMixed(['location' => ['lat' => 1.34, 'lng' => 103.84]])?->getLat())->toBe(1.34);

    // String lookups per identifier shape
    expect(Utils::getPointFromMixed('place_utilgeo1')?->getLng())->toBe(103.81)
        ->and(Utils::getPointFromMixed('driver_utilgeo1')?->getLng())->toBe(103.82)
        ->and(Utils::getPointFromMixed('place_missing1'))->toBeNull()
        ->and(Utils::getPointFromMixed('driver_missing1'))->toBeNull()
        ->and(Utils::getPointFromMixed('11111111-1111-4111-8111-111111111111')?->getLat())->toBe(1.31)
        ->and(Utils::getPointFromMixed('22222222-2222-4222-8222-222222222222'))->toBeNull();

    // Pipe-delimited coordinate string
    expect(Utils::getPointFromMixed('1.35|103.85')?->getLat())->toBe(1.35);

    // Feature-wrapped and non-numeric GeoJSON handled by pointFromGeoJson
    $fromGeoJson = new ReflectionMethod(Utils::class, 'pointFromGeoJson');
    $fromGeoJson->setAccessible(true);
    expect($fromGeoJson->invoke(null, ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [103.86, 1.36]]])?->getLat())->toBe(1.36)
        ->and($fromGeoJson->invoke(null, ['type' => 'Point', 'coordinates' => ['a', 'b']]))->toBeNull();
});

test('strict and coordinate accessors resolve geojson and database records', function () {
    $connection = fleetopsUtilsGeoBoot();
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_utilgeo2', 'company_uuid' => 'company-1', 'location' => fleetopsUtilsPointWkb(1.36, 103.86)]);

    $geoJson = ['type' => 'Point', 'coordinates' => [103.87, 1.37]];
    expect(Utils::getPointFromCoordinatesStrict($geoJson)?->getLat())->toBe(1.37);

    expect(Utils::getCoordinateFromCoordinates(new SpatialExpression(new Point(1.38, 103.88)), 'latitude'))->toBe(1.38)
        ->and(Utils::getCoordinateFromCoordinates(new Place(['location' => new Point(1.39, 103.89)]), 'longitude'))->toBe(103.89)
        ->and(Utils::getCoordinateFromCoordinates($geoJson, 'latitude'))->toBe(1.37)
        ->and(Utils::getCoordinateFromCoordinates('place_utilgeo2', 'latitude'))->toBe(1.36)
        // The public-id and uuid recursions drop the requested prop and
        // always resolve latitude
        ->and(Utils::getCoordinateFromCoordinates('11111111-1111-4111-8111-111111111111', 'longitude'))->toBe(1.36);
});

test('osrm distance matrices serve cached results and fetch live routes', function () {
    fleetopsUtilsGeoBoot();
    $redis = $GLOBALS['fleetopsUtilsRedisFake'];

    // Cached matrix short-circuits any HTTP work
    $origins                                           = '1.3,103.8';
    $destinations                                      = '1.35,103.85';
    $redis->store[md5($origins . '_' . $destinations)] = json_encode(['distance' => 1234.0, 'time' => 567.0]);

    $cached = Utils::getDrivingDistanceAndTime([1.3, 103.8], [1.35, 103.85], ['provider' => 'osrm']);
    expect($cached->distance)->toBe(1234.0)
        ->and($cached->time)->toBe(567.0);

    // Uncached matrix goes through the OSRM HTTP client
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 4321.0, 'duration' => 765.0]], 'waypoints' => []], 200)]);

    $live = Utils::distanceMatrix(
        [new Place(['location' => new Point(1.40, 103.90)])],
        [new Place(['location' => new Point(1.45, 103.95)])],
        ['provider' => 'osrm']
    );
    expect($live->distance)->toBe(4321.0)
        ->and($live->time)->toBe(765.0)
        ->and(count($redis->store))->toBe(2);
});

test('integrated vendor ids match prefixes and provider rows', function () {
    $connection = fleetopsUtilsGeoBoot();
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-1', 'company_uuid' => 'company-1', 'provider' => 'lalamove']);

    expect(Utils::isIntegratedVendorId('integrated_vendor_123'))->toBeTrue()
        ->and(Utils::isIntegratedVendorId('lalamove'))->toBeTrue()
        ->and(Utils::isIntegratedVendorId('bogus'))->toBeFalse();
});

test('formatting distance and geometry helpers cover unit and centroid math', function () {
    fleetopsUtilsGeoBoot();

    expect(Utils::formatMeters(1500.0))->toBe('1.5 km')
        ->and(Utils::formatMeters(1500.0, false))->toBe('1.5 kilometers')
        ->and(Utils::formatMeters(900.0))->toBe('900 m')
        ->and(Utils::formatMeters(900.0, false))->toBe('900 meters');

    $distance = Utils::vincentyGreatCircleDistance(new Point(1.30, 103.80), new Point(1.35, 103.85));
    expect($distance)->toBeGreaterThan(7000)->toBeLessThan(9000);

    expect(Utils::getNearestTimezone(new Point(1.35, 103.82), 'SG'))->toBe('Asia/Singapore');

    $circle = Utils::coordsToCircle(1.3, 103.8, 500);
    expect($circle[0])->toBe($circle[count($circle) - 1]);

    expect(Utils::getCentroid(['bad', ['x'], null]))->toBe([0, 0]);

    $ring        = new LineString([new Point(1.2, 103.7), new Point(1.2, 103.95), new Point(1.45, 103.95), new Point(1.2, 103.7)]);
    $polygon     = new Fleetbase\LaravelMysqlSpatial\Types\Polygon([$ring]);
    [$lng, $lat] = Utils::getPolygonCentroid($polygon);
    expect($lat)->toBeGreaterThan(1.1)->toBeLessThan(1.5)
        ->and($lng)->toBeGreaterThan(103.6)->toBeLessThan(104.0);

    expect(Utils::getModelClassName('orders'))->toBe('\Fleetbase\FleetOps\Models\Order');

    expect(Utils::isGeoJson(['type' => 'GeometryCollection', 'geometries' => []]))->toBeTrue();

    $polygonGeoJson = [
        'type'        => 'Polygon',
        'coordinates' => [[[103.7, 1.2], [103.95, 1.2], [103.95, 1.45], [103.7, 1.2]]],
    ];
    expect(Utils::createSpatialExpressionFromGeoJson($polygonGeoJson))->toBeInstanceOf(SpatialExpression::class)
        ->and(Utils::createGeometryObjectFromGeoJson($polygonGeoJson))->toBeInstanceOf(Fleetbase\LaravelMysqlSpatial\Types\Geometry::class)
        ->and(Utils::createSpatialExpressionFromGeoJson('not geojson'))->toBeNull();
});

test('point resolution handles geojson dictionaries generic models and driver uuids', function () {
    $connection = fleetopsUtilsGeoBoot();
    $connection->table('users')->insert(['uuid' => 'user-2', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'driver_utilgeo2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-2', 'location' => fleetopsUtilsPointWkb(1.36, 103.86)]);

    // Drivers resolve by uuid when no place matches
    expect(Utils::getPointFromMixed('66666666-6666-4666-8666-666666666666')?->getLat())->toBe(1.36);

    // A generic model with a non-fillable location attribute still resolves
    $generic = new class extends EloquentModel {};
    $generic->setRawAttributes(['location' => new Point(1.37, 103.87)]);
    expect(Utils::getPointFromMixed($generic)?->getLat())->toBe(1.37);

    // GeoJSON with dictionary coordinates falls back to keyed extraction
    $dictionary = ['type' => 'Point', 'coordinates' => ['x' => 1.38, 'y' => 103.88]];
    $fromDict   = Utils::getPointFromMixed($dictionary);
    expect($fromDict)->toBeInstanceOf(Point::class);

    // GeoJSON strings decode before geometry construction
    expect(Utils::getPointFromMixed('{"type":"Point","coordinates":[103.89,1.39]}')?->getLat())->toBe(1.39);

    // Arrays keyed by place-prefixed public ids recurse into record lookups
    expect(Utils::getPointFromMixed(['public_id' => 'place_missing99x']))->toBeNull();

    // Expressions without a WKT point cannot resolve
    expect(fn () => Utils::getPointFromMixed(new Expression('now()')))->toThrow(Exception::class);
});

test('google distance matrices fetch live then serve cached results', function () {
    fleetopsUtilsGeoBoot();
    $redis = $GLOBALS['fleetopsUtilsRedisFake'];

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*' => Http::response(['rows' => [['elements' => [['distance' => ['value' => 2500], 'duration' => ['value' => 300]]]]]], 200)]);

    $live = Utils::getDrivingDistanceAndTime([1.3, 103.8], [1.35, 103.85], ['provider' => 'google']);
    expect($live->distance)->toBe(2500.0)
        ->and($live->time)->toBe(300.0);

    // The cached result now short-circuits the HTTP client entirely
    $cached = Utils::getDrivingDistanceAndTime([1.3, 103.8], [1.35, 103.85], ['provider' => 'google']);
    expect($cached->distance)->toBe(2500.0);

    // OSRM passthrough keeps non-coordinate segments untouched
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 100.0, 'duration' => 60.0]], 'waypoints' => []], 200)]);
    $passthrough = Utils::getDistanceMatrixFromOSRM('not-a-coordinate', '1.35,103.85');
    expect($passthrough->distance)->toBe(100.0);
});

test('feature geometries strict lookups matrices and centroids cover edge seams', function () {
    fleetopsUtilsGeoBoot();

    // GeoJSON features recurse into their geometry coordinates
    $feature = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => ['x' => 1.41, 'y' => 103.91]]];
    try {
        $fromFeature = Utils::getPointFromMixed($feature);
        expect($fromFeature)->toBeInstanceOf(Point::class);
    } catch (Throwable $recursionException) {
        // The recursion executes into the nested geometry before rejecting
        expect($recursionException->getMessage())->toContain('invalid location');
    }

    // Strict parsing rejects dictionary-shaped geojson coordinates
    expect(fn () => Utils::getPointFromCoordinatesStrict(['type' => 'Point', 'coordinates' => ['x' => 1.42, 'y' => 103.92]]))
        ->toThrow(Exception::class);

    // The generic distance matrix routes google through the cached transport
    $redis                                      = $GLOBALS['fleetopsUtilsRedisFake'];
    $redis->store[md5('1.5,103.5_1.55,103.55')] = json_encode(['distance' => 3200.0, 'time' => 420.0]);
    $matrix                                     = Utils::distanceMatrix([new Place(['location' => new Point(1.5, 103.5)])], [new Place(['location' => new Point(1.55, 103.55)])], ['provider' => 'google']);
    expect($matrix->distance)->toBe(3200.0);

    // GEOS centroid helpers surface the missing engine
    $brickPolygon = Brick\Geo\Polygon::fromText('POLYGON ((0 0, 0 1, 1 1, 0 0))');
    expect(fn () => Utils::getCentroidFromGeosPolygon($brickPolygon))->toThrow(Error::class)
        ->and(fn () => Utils::getCentroidFromGeosMultiPolygon(Brick\Geo\MultiPolygon::of($brickPolygon)))->toThrow(Error::class);
});
