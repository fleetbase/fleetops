<?php

if (!function_exists('Fleetbase\FleetOps\Support\resource_path')) {
    eval('namespace Fleetbase\FleetOps\Support; function resource_path($path = "") { return getcwd() . "/server/" . ltrim($path, "/"); }');
}

if (!function_exists('Fleetbase\FleetOps\Support\config')) {
    eval('namespace Fleetbase\FleetOps\Support; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\env')) {
    eval('namespace Fleetbase\FleetOps\Support; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Fleetbase\Models\Company;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class FleetOpsUtilsRedisFake
{
    public array $sets = [];

    public function __construct(private array $queuedGets = [])
    {
    }

    public function get(string $key): mixed
    {
        return array_shift($this->queuedGets);
    }

    public function set(string $key, string $value): bool
    {
        $this->sets[] = [$key, $value];

        return true;
    }
}

function fleetopsUtilsAdditionalPoint(float $lat = 47.9131423, float $lng = 106.9338169): Point
{
    return new Point($lat, $lng);
}

function fleetopsUtilsAdditionalPolygon(): Polygon
{
    return new Polygon([
        new LineString([
            new Point(1.30, 103.80),
            new Point(1.30, 103.90),
            new Point(1.40, 103.90),
            new Point(1.40, 103.80),
            new Point(1.30, 103.80),
        ]),
    ]);
}

test('company transaction currency prefers organization currency and falls back to usd', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-test',
        'currency' => 'mnt',
    ], true);

    expect(Utils::getCompanyTransactionCurrency($company))->toBe('MNT')
        ->and(Utils::getCompanyTransactionCurrency())->toBe('USD');
});

test('mixed point resolver reads location-bearing model instances', function () {
    $place   = new Place();
    $driver  = new Driver();
    $vehicle = new Vehicle();
    $generic = new class extends EloquentModel {
        protected $fillable = ['location'];
    };

    $place->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(1.1, 2.2)], true);
    $driver->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(3.3, 4.4)], true);
    $vehicle->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(5.5, 6.6)], true);
    $generic->setRawAttributes(['location' => fleetopsUtilsAdditionalPoint(7.7, 8.8)], true);

    expect(Utils::getPointFromMixed($place)->getLng())->toEqual(2.2)
        ->and(Utils::getPointFromMixed($driver)->getLat())->toEqual(3.3)
        ->and(Utils::getPointFromMixed($vehicle)->getLng())->toEqual(6.6)
        ->and(Utils::getPointFromMixed($generic)->getLat())->toEqual(7.7);
});

test('strict point resolver accepts spatial expressions points geojson and string formats', function () {
    $point             = fleetopsUtilsAdditionalPoint();
    $spatialExpression = new SpatialExpression($point);
    $geoJsonObject     = (object) [
        'type'        => 'Point',
        'coordinates' => [106.9338169, 47.9131423],
    ];

    expect(Utils::getPointFromCoordinatesStrict($spatialExpression)->getLat())->toEqual(47.9131423)
        ->and(Utils::getPointFromCoordinatesStrict($point))->toBe($point)
        ->and(Utils::getPointFromCoordinatesStrict($geoJsonObject)->getLng())->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinatesStrict('POINT(106.9338169 47.9131423)')->getLat())->toEqual(47.9131423)
        ->and(Utils::getPointFromCoordinatesStrict('LatLng(47.9131423, 106.9338169)')->getLng())->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinatesStrict('47.9131423,106.9338169')->getLat())->toEqual(47.9131423)
        ->and(Utils::getPointFromCoordinatesStrict('47.9131423|106.9338169')->getLng())->toEqual(106.9338169)
        ->and(Utils::getPointFromCoordinatesStrict('47.9131423 106.9338169')->getLat())->toEqual(47.9131423);
});

test('vendor and country helpers resolve prefix and globe-backed polygons', function () {
    $polygon = Utils::createPolygonFromCountry('mn');

    expect(Utils::isIntegratedVendorId('integrated_vendor_demo'))->toBeTrue()
        ->and($polygon)->toBeInstanceOf(MultiPolygon::class)
        ->and(Utils::createPolygonFromCountry('missing-country-code'))->toBeNull();
});

test('polygon helpers return centroids and coordinate rings from spatial shapes', function () {
    $polygon      = fleetopsUtilsAdditionalPolygon();
    $multiPolygon = new MultiPolygon([$polygon]);
    $coordinates  = Utils::getCoordinatesFromPolygon($polygon);

    expect(Utils::getPolygonCentroid($polygon))->toBe([103.84, 1.34])
        ->and(Utils::getMultiPolygonCentroid($multiPolygon))->toBe([103.84, 1.34])
        ->and($coordinates)->toHaveCount(5)
        ->and($coordinates[0])->toBe([103.8, 1.3]);
});

test('distance matrix helpers return cached google results without http calls', function () {
    Redis::swap(new FleetOpsUtilsRedisFake([
        json_encode(['distance' => 1234, 'time' => 567]),
    ]));

    $matrix = Utils::getDistanceMatrixFromGoogle('47.9131423,106.9338169', '47.9141423,106.9348169');

    expect($matrix->distance)->toBe(1234.0)
        ->and($matrix->time)->toBe(567.0);
});

test('distance matrix helpers parse live google and osrm provider responses', function () {
    Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    Http::swap($http = new Illuminate\Http\Client\Factory());

    $redis = new FleetOpsUtilsRedisFake([null, null]);
    Redis::swap($redis);

    $http->fake(function ($request) {
        $url = (string) $request->url();

        return match (true) {
            str_contains($url, 'maps.googleapis.com/maps/api/distancematrix/json') => Http::response([
                'rows' => [
                    [
                        'elements' => [
                            [
                                'distance' => ['value' => 2222],
                                'duration' => ['value' => 333],
                            ],
                        ],
                    ],
                ],
            ]),
            str_contains($url, '/route/v1/driving/') => Http::response([
                'code'   => 'Ok',
                'routes' => [
                    [
                        'distance' => 4444,
                        'duration' => 555,
                    ],
                ],
            ]),
            default => Http::response(['code' => 'Unexpected'], 500),
        };
    });

    $google = Utils::getDrivingDistanceAndTime([47.9131423, 106.9338169], [47.9141423, 106.9348169], ['provider' => 'google']);
    $osrm   = Utils::distanceMatrix([[47.9131423, 106.9338169]], [[47.9141423, 106.9348169]], ['provider' => 'osrm']);

    expect($google->distance)->toBe(2222.0)
        ->and($google->time)->toBe(333.0)
        ->and($osrm->distance)->toBe(4444.0)
        ->and($osrm->time)->toBe(555.0)
        ->and($redis->sets)->toHaveCount(2);
});

test('utils geojson features countries and sql fallbacks cover edge branches', function () {
    // GeoJSON Feature wrappers resolve their nested geometry coordinates
    $feature = [
        'type'     => 'Feature',
        'geometry' => [
            'type'        => 'Point',
            'coordinates' => [103.87, 1.36],
        ],
    ];
    $point = Utils::getPointFromMixed($feature);
    expect($point)->toBeInstanceOf(Point::class)
        ->and($point->getLat())->toBe(1.36);

    // Geometry objects build from raw geojson strings
    $geometry = Utils::createGeometryObjectFromGeoJson(json_encode([
        'type'        => 'Point',
        'coordinates' => [103.88, 1.37],
    ]));
    expect($geometry)->toBeInstanceOf(Fleetbase\LaravelMysqlSpatial\Types\Geometry::class);

    // Unknown countries scan every globe feature without matching
    expect(Utils::createPolygonFromCountry('zz'))->toBeNull();

    // Broken database bindings fall back to mysql-flavoured sql helpers
    $previous = app('db');
    app()->instance('db', new class {
        public function connection($name = null)
        {
            throw new RuntimeException('db offline');
        }

        public function __call($method, $arguments)
        {
            throw new RuntimeException('db offline');
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    expect(Utils::sqlNow())->toBe('NOW()');
    app()->instance('db', $previous);
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
});
