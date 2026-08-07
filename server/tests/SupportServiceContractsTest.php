<?php

use Fleetbase\FleetOps\Scopes\DriverScope;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FleetOpsTaggedCacheFake
{
    public array $calls = [];

    public function __construct(private mixed $rememberValue = null, private bool $throwOnFlush = false)
    {
    }

    public function remember($key, $ttl, Closure $callback)
    {
        $this->calls[] = ['remember', $key, $ttl];

        return $this->rememberValue ?? $callback();
    }

    public function flush()
    {
        $this->calls[] = ['flush'];

        if ($this->throwOnFlush) {
            throw new RuntimeException('tagged cache is unavailable');
        }
    }
}

class FleetOpsLiveCacheFake
{
    public array $gets                           = [];
    public array $increments                     = [];
    public array $tags                           = [];
    public ?FleetOpsTaggedCacheFake $taggedCache = null;
    public bool $throwOnTags                     = false;
    private array $versions                      = [
        'live:company-1:orders:version'  => 3,
        'live:company-1:drivers:version' => 1,
    ];

    public function get($key, $default = null)
    {
        $this->gets[] = [$key, $default];

        return $this->versions[$key] ?? $default;
    }

    public function increment($key)
    {
        $this->increments[] = $key;

        $this->versions[$key] = ($this->versions[$key] ?? 0) + 1;

        return $this->versions[$key];
    }

    public function tags(array $tags)
    {
        $this->tags[] = $tags;

        if ($this->throwOnTags) {
            throw new RuntimeException('tagged cache is unavailable');
        }

        return $this->taggedCache ??= new FleetOpsTaggedCacheFake();
    }
}

class FleetOpsDriverScopeBuilderFake extends Builder
{
    public array $whereHas = [];

    public function __construct()
    {
    }

    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        $this->whereHas[] = [$relation, $callback, $operator, $count];

        return $this;
    }
}

afterEach(function () {
    Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    Http::preventStrayRequests(false);
});

test('live cache service builds company scoped keys and tags', function () {
    session(['company' => 'company-1']);

    $cache = new FleetOpsLiveCacheFake();
    Cache::swap($cache);

    $params = ['active' => true, 'bounds' => [1, 2, 3, 4]];

    expect(LiveCacheService::getCacheKey('orders', $params))
        ->toBe('live:company-1:orders:v3:' . md5(json_encode($params)))
        ->and(LiveCacheService::getTags())->toBe(['live:company-1'])
        ->and(LiveCacheService::getEndpointTags('orders'))->toBe([
            'live:company-1',
            'live:company-1:orders',
        ])
        ->and($cache->gets)->toBe([['live:company-1:orders:version', 0]]);
});

test('live cache service increments versions and remembers tagged values', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->taggedCache = new FleetOpsTaggedCacheFake();
    Cache::swap($cache);

    expect(LiveCacheService::incrementVersion('drivers'))->toBe(2)
        ->and(LiveCacheService::remember('drivers', ['limit' => 25], fn () => ['fresh' => true], 45))->toBe([
            'fresh' => true,
        ])
        ->and($cache->increments)->toBe(['live:company-1:drivers:version'])
        ->and($cache->tags)->toBe([['live:company-1', 'live:company-1:drivers']])
        ->and($cache->taggedCache->calls)->toBe([
            ['remember', 'live:company-1:drivers:v2:' . md5(json_encode(['limit' => 25])), 45],
        ]);
});

test('live cache service invalidates endpoints with version bumps and optional tag flushes', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->taggedCache = new FleetOpsTaggedCacheFake();
    Cache::swap($cache);

    LiveCacheService::invalidate('vehicles');

    expect($cache->increments)->toBe(['live:company-1:vehicles:version'])
        ->and($cache->tags)->toBe([['live:company-1', 'live:company-1:vehicles']])
        ->and($cache->taggedCache->calls)->toBe([['flush']]);

    // Stores without tag support still get the version bump, which is what
    // actually invalidates; the failed flush is swallowed.
    $untagged              = new FleetOpsLiveCacheFake();
    $untagged->throwOnTags = true;
    Cache::swap($untagged);

    LiveCacheService::invalidate('vehicles');

    expect($untagged->increments)->toBe(['live:company-1:vehicles:version'])
        ->and($untagged->tags)->toBe([['live:company-1', 'live:company-1:vehicles']]);
});

test('live cache service invalidates all endpoints and ignores unsupported tag flushes', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->throwOnTags = true;
    Cache::swap($cache);

    LiveCacheService::invalidate();

    expect($cache->increments)->toBe([
        'live:company-1:orders:version',
        'live:company-1:routes:version',
        'live:company-1:coordinates:version',
        'live:company-1:drivers:version',
        'live:company-1:vehicles:version',
        'live:company-1:places:version',
        'live:company-1:operations-monitor:version',
    ])->and($cache->tags)->toBe([['live:company-1']]);
});

test('live cache service invalidates multiple endpoints and ignores tag flush failures', function () {
    session(['company' => 'company-1']);

    $cache              = new FleetOpsLiveCacheFake();
    $cache->throwOnTags = true;
    Cache::swap($cache);

    LiveCacheService::invalidateMultiple(['orders', 'drivers']);

    expect($cache->increments)->toBe([
        'live:company-1:orders:version',
        'live:company-1:drivers:version',
    ])->and($cache->tags)->toBe([['live:company-1', 'live:company-1:orders']]);
});

test('driver scope requires an active user relationship', function () {
    $builder = new FleetOpsDriverScopeBuilderFake();
    $model   = new class extends Model {
    };

    expect((new DriverScope())->apply($builder, $model))->toBeNull()
        ->and($builder->whereHas)->toHaveCount(1)
        ->and($builder->whereHas[0][0])->toBe('user');
});

test('osrm decodes polyline route geometry fixtures', function () {
    $points = OSRM::decodePolyline('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

    expect($points)->toHaveCount(3)
        ->and($points[0])->toBeInstanceOf(Point::class)
        ->and($points[0]->getLat())->toBe(-120.2)
        ->and($points[0]->getLng())->toBe(38.5);
});

test('osrm support covers nearest table trip match tile and error fallback requests', function () {
    Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    app('config')->set('fleetops.osrm.host', 'https://osrm-other.test/');

    Http::fake(function ($request) {
        $url = (string) $request->url();

        return match (true) {
            str_contains($url, '/nearest/v1/driving/') => Http::response(['code' => 'Ok', 'waypoints' => [['name' => 'road']]]),
            str_contains($url, '/table/v1/driving/')   => Http::response(['code' => 'Ok', 'durations' => [[0, 10], [10, 0]]]),
            str_contains($url, '/trip/v1/driving/')    => Http::response(['code' => 'Ok', 'trips' => [['distance' => 100]]]),
            str_contains($url, '/match/v1/driving/')   => Http::response(['code' => 'Ok', 'matchings' => [['confidence' => 1]]]),
            str_contains($url, '/tile/v1/car/')        => Http::response('tile-bytes'),
            str_contains($url, '/route/v1/driving/')   => throw new RuntimeException('timeout'),
            default                                    => Http::response(['code' => 'Unexpected'], 500),
        };
    });

    $points = [
        new Point(1.30, 103.80),
        new Point(1.31, 103.81),
    ];

    expect(OSRM::getNearest($points[0], ['number' => 1]))->toMatchArray(['code' => 'Ok'])
        ->and(OSRM::getTable($points, ['annotations' => 'duration'])['durations'][0][1])->toBe(10)
        ->and(OSRM::getTrip($points, ['roundtrip' => 'false'])['trips'][0]['distance'])->toBe(100)
        ->and(OSRM::getMatch($points, ['geometries' => 'polyline'])['matchings'][0]['confidence'])->toBe(1)
        ->and(OSRM::getTile(1, 2, 3, ['foo' => 'bar']))->toBe('tile-bytes')
        ->and(OSRM::getRouteFromCoordinatesString('103.8,1.3;103.81,1.31'))->toBe(['code' => 'Error', 'routes' => []]);

    expect(fn () => OSRM::getRouteFromPoints([$points[0]]))->toThrow(InvalidArgumentException::class, 'At least two points');
});

test('osrm converts mixed points decodes route polylines and serves cached results', function () {
    Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    app('config')->set('fleetops.osrm.host', 'https://osrm-mixed.test/');

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(function ($request) {
        $url = (string) $request->url();

        return match (true) {
            str_contains($url, '/route/v1/driving/')   => Http::response(['code' => 'Ok', 'routes' => [['geometry' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@', 'distance' => 42]]]),
            str_contains($url, '/nearest/v1/driving/') => Http::response(['code' => 'Ok', 'waypoints' => [['name' => 'cached-road']]]),
            str_contains($url, '/table/v1/driving/')   => Http::response(['code' => 'Ok', 'durations' => [[0, 5], [5, 0]]]),
            str_contains($url, '/trip/v1/driving/')    => Http::response(['code' => 'Ok', 'trips' => [['distance' => 77]]]),
            str_contains($url, '/match/v1/driving/')   => Http::response(['code' => 'Ok', 'matchings' => [['confidence' => 0.9]]]),
            default                                    => Http::response(['code' => 'Unexpected'], 500),
        };
    });

    // Mixed point representations convert through getPointFromMixed, and the
    // successful route response decodes its polyline into waypoints
    $mixedPoints = [
        ['latitude' => 1.42, 'longitude' => 103.92],
        new Point(1.43, 103.93),
    ];
    $route = OSRM::getRouteFromPoints($mixedPoints);
    expect($route['routes'][0]['waypoints'])->not->toBeEmpty()
        ->and($route['routes'][0]['distance'])->toBe(42);

    // Second identical calls come straight from the cache
    $point = new Point(1.30, 103.80);
    $first = OSRM::getNearest($point, ['number' => 2]);
    expect(OSRM::getNearest($point, ['number' => 2]))->toBe($first);

    $table = OSRM::getTable($mixedPoints, ['annotations' => 'distance']);
    expect(OSRM::getTable($mixedPoints, ['annotations' => 'distance']))->toBe($table);

    $trip = OSRM::getTrip($mixedPoints, ['roundtrip' => 'true']);
    expect(OSRM::getTrip($mixedPoints, ['roundtrip' => 'true']))->toBe($trip);

    $match = OSRM::getMatch($mixedPoints, ['geometries' => 'polyline6']);
    expect(OSRM::getMatch($mixedPoints, ['geometries' => 'polyline6'])['matchings'][0]['confidence'])->toBe(0.9);
});
