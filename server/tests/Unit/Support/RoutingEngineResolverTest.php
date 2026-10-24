<?php

use Fleetbase\FleetOps\Support\Routing\HostPolicy;
use Fleetbase\FleetOps\Support\Routing\RoutingEngineResolver;
use Fleetbase\FleetOps\Support\Routing\RoutingProviderPolicy;
use Fleetbase\FleetOps\Support\Routing\ValhallaClient;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Covers how order distance and time pick a road-routing provider (explicit
 * DISTANCE_MATRIX_PROVIDER, then the company's routing engine when the server
 * can call it, then the straight-line estimate) and the fallback to that
 * estimate when a road provider fails or finds no route.
 */
function fleetopsDistanceProviderBoot(): SQLiteConnection
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
    DB::clearResolvedInstance('db');

    $connection->getSchemaBuilder()->create('settings', function ($blueprint) {
        $blueprint->increments('id');
        $blueprint->string('key')->nullable();
        $blueprint->text('value')->nullable();
        $blueprint->timestamps();
    });

    config([
        'fleetops.distance_matrix.provider'    => null,
        'fleetops.osrm.host'                   => 'https://osrm.fleetbase.test',
        'fleetops.valhalla.host'               => null,
        'fleetops.valhalla.api_key'            => null,
        'fleetops.routing.google_enabled'      => false,
        'fleetops.routing.display_engine'      => 'osrm',
        'fleetops.routing.optimization_engine' => null,
        'fleetops.routing.server_routing'      => 'road',
        'services.google_maps.api_key'         => null,
    ]);
    Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));

    $redis = new class {
        public array $store = [];
        public array $sets  = [];

        public function get($key)
        {
            return $this->store[$key] ?? null;
        }

        public function set($key, $value, ...$options)
        {
            $this->store[$key] = $value;
            $this->sets[]      = [$key, $value, ...$options];

            return true;
        }
    };
    app()->instance('redis', $redis);
    Redis::clearResolvedInstance('redis');
    $GLOBALS['fleetopsDistanceProviderRedis'] = $redis;

    $log = new class {
        public array $warnings = [];

        public function warning($message, array $context = [])
        {
            $this->warnings[] = [$message, $context];
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };
    app()->instance('log', $log);
    Log::clearResolvedInstance('log');
    $GLOBALS['fleetopsDistanceProviderLog'] = $log;

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);

    return $connection;
}

function fleetopsDistanceProviderRouting(SQLiteConnection $connection, string $companyUuid, array $routing): void
{
    $connection->table('settings')->insert(['key' => 'company.' . $companyUuid . '.routing', 'value' => json_encode($routing)]);
}

function fleetopsDistanceProviderPolicy(array $policy): void
{
    DB::table('settings')->where('key', RoutingProviderPolicy::SETTING_KEY)->delete();
    RoutingProviderPolicy::save($policy);
}

beforeEach(function () {
    // Example hosts resolve to a public address unless a test says otherwise.
    HostPolicy::resolveHostsUsing(fn (string $host) => str_starts_with($host, 'internal.') ? ['10.0.0.12'] : ['93.184.216.34']);
});

afterEach(function () {
    HostPolicy::resolveHostsUsing(null);
    config(['fleetops.valhalla.allow_private_hosts' => false]);
    config([
        'fleetops.distance_matrix.provider'    => null,
        'fleetops.osrm.host'                   => null,
        'fleetops.valhalla.host'               => null,
        'fleetops.valhalla.api_key'            => null,
        'fleetops.routing.google_enabled'      => false,
        'fleetops.routing.display_engine'      => 'osrm',
        'fleetops.routing.optimization_engine' => null,
        'fleetops.routing.server_routing'      => 'road',
        'services.google_maps.api_key'         => null,
    ]);
    Cache::flush();
    app()->forgetInstance('log');
    Log::clearResolvedInstance('log');
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    session(['company' => null]);
});

test('environment values only seed the admin policy, which wins once saved', function () {
    $connection = fleetopsDistanceProviderBoot();
    config([
        'fleetops.routing.display_engine'      => 'valhalla',
        'fleetops.routing.optimization_engine' => null,
        'fleetops.routing.server_routing'      => 'straight_line',
    ]);
    Cache::flush();

    // Unsaved: the environment provides the defaults, and optimization follows the routing engine
    expect(RoutingProviderPolicy::settings()['defaults'])->toBe(['display_engine' => 'valhalla', 'optimization_engine' => 'valhalla'])
        ->and(RoutingProviderPolicy::usesStraightLine())->toBeTrue()
        ->and(RoutingEngineResolver::resolve('company-1'))->toBe('calculate');

    // Saved: the admin choice wins over the environment
    fleetopsDistanceProviderPolicy(['server_routing' => 'road', 'defaults' => ['display_engine' => 'osrm']]);
    expect(RoutingProviderPolicy::usesStraightLine())->toBeFalse()
        ->and(RoutingEngineResolver::resolve('company-1'))->toBe('osrm');

    // The old DISTANCE_MATRIX_PROVIDER no longer overrides anything for orders
    config(['fleetops.distance_matrix.provider' => 'google']);
    expect(RoutingEngineResolver::resolve('company-1'))->toBe('osrm');

    // An unknown mode is treated as road routing
    expect(RoutingProviderPolicy::normalize(['server_routing' => 'teleport'])['server_routing'])->toBe('road');
});

test('the administrator can switch server-side routing to straight-line in one place', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response(['code' => 'Ok', 'routes' => [['distance' => 31520.0, 'duration' => 1610.0]]]);
    });

    expect(RoutingEngineResolver::resolve('company-1'))->toBe('osrm')
        ->and(RoutingEngineResolver::trackingProvider('company-1'))->toBe('osrm');

    fleetopsDistanceProviderPolicy(['server_routing' => 'straight_line']);
    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);

    expect(RoutingEngineResolver::resolve('company-1'))->toBe('calculate')
        ->and(RoutingEngineResolver::trackingProvider('company-1'))->toBe('calculated')
        ->and(Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['company_uuid' => 'company-1'])->distance)->toBe($estimate->distance)
        ->and($requests)->toBe(0);
});

test('the company routing engine is used when the server is configured for it', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-osrm', ['router' => 'osrm', 'display_engine' => 'osrm']);
    fleetopsDistanceProviderRouting($connection, 'company-valhalla', ['display_engine' => 'valhalla']);
    fleetopsDistanceProviderRouting($connection, 'company-legacy', ['router' => 'valhalla']);
    fleetopsDistanceProviderRouting($connection, 'company-vroom', ['display_engine' => 'vroom']);

    // A company that never saved routing settings uses OSRM, as the map does
    expect(RoutingEngineResolver::resolve('company-osrm'))->toBe('osrm')
        ->and(RoutingEngineResolver::resolve('company-unsaved'))->toBe('osrm')
        ->and(RoutingEngineResolver::resolve(null))->toBe('osrm');

    // Valhalla needs a configured server; until then the company falls through to private OSRM
    expect(RoutingEngineResolver::resolve('company-valhalla'))->toBe('osrm');
    config(['fleetops.valhalla.host' => 'https://valhalla.routing.fleetbase.test']);
    expect(RoutingEngineResolver::resolve('company-valhalla'))->toBe('valhalla')
        ->and(RoutingEngineResolver::resolve('company-legacy'))->toBe('valhalla')
        ->and(RoutingEngineResolver::resolve('company-osrm'))->toBe('osrm');

    // Engines the server cannot call are skipped for the platform default
    expect(RoutingEngineResolver::companyEngine('company-vroom'))->toBe('vroom')
        ->and(RoutingEngineResolver::isAvailable('vroom'))->toBeFalse()
        ->and(RoutingEngineResolver::resolve('company-vroom'))->toBe('osrm')
        ->and(RoutingEngineResolver::companyEngine('company-unsaved'))->toBeNull();
});

test('google needs the administrator to allow it, globally or for the organization', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-google', ['display_engine' => 'google']);
    fleetopsDistanceProviderRouting($connection, 'company-other', ['display_engine' => 'google']);
    config(['services.google_maps.api_key' => 'test-maps-key', 'fleetops.osrm.host' => 'https://router.project-osrm.org']);

    expect(RoutingEngineResolver::companyEngine('company-google'))->toBe('google')
        ->and(RoutingEngineResolver::isAvailable('google', 'company-google'))->toBeFalse()
        ->and(RoutingEngineResolver::resolve('company-google'))->toBe('calculate')
        ->and(RoutingEngineResolver::googleMapsApiKey())->toBe('test-maps-key');

    // With the platform's own Valhalla configured, the organization gets road distance from it instead
    config(['fleetops.valhalla.host' => 'https://valhalla.routing.fleetbase.test']);
    expect(RoutingEngineResolver::resolve('company-google'))->toBe('valhalla');

    // An exception lets one organization use Google while the others stay on Valhalla
    fleetopsDistanceProviderPolicy(['exceptions' => [['company_uuid' => 'company-google', 'company_name' => 'Acme', 'providers' => ['google']]]]);
    expect(RoutingEngineResolver::resolve('company-google'))->toBe('google')
        ->and(RoutingEngineResolver::resolve('company-other'))->toBe('valhalla');

    // Without a Maps key Google cannot be used even when allowed
    config(['services.google_maps.api_key' => null]);
    expect(RoutingEngineResolver::resolve('company-google'))->toBe('valhalla');
});

test('the platform valhalla server serves companies whose engine is not available', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-osrm', ['display_engine' => 'osrm']);
    config(['fleetops.osrm.host' => 'https://router.project-osrm.org']);

    expect(RoutingEngineResolver::resolve('company-osrm'))->toBe('calculate');

    // Community servers do not count as configured
    config(['fleetops.valhalla.host' => 'https://VALHALLA1.openstreetmap.de']);
    expect(ValhallaClient::isConfigured())->toBeFalse()
        ->and(RoutingEngineResolver::resolve('company-osrm'))->toBe('calculate');

    config(['fleetops.valhalla.host' => 'https://valhalla.routing.fleetbase.test/']);
    expect(ValhallaClient::isConfigured())->toBeTrue()
        ->and(ValhallaClient::connection())->toBe(['host' => 'https://valhalla.routing.fleetbase.test', 'api_key' => null])
        ->and(RoutingEngineResolver::resolve('company-osrm'))->toBe('valhalla')
        ->and(RoutingEngineResolver::resolve(null))->toBe('valhalla');
});

test('order distance from valhalla converts kilometres, sends the key and caches the result', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);
    config([
        'fleetops.osrm.host'        => 'https://router.project-osrm.org',
        'fleetops.valhalla.host'    => 'https://valhalla.routing.fleetbase.test',
        'fleetops.valhalla.api_key' => 'test-valhalla-key',
    ]);
    $redis = $GLOBALS['fleetopsDistanceProviderRedis'];

    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request;

        return Http::response(['trip' => ['status' => 0, 'summary' => ['length' => 31.52, 'time' => 1610.0], 'legs' => []]]);
    });

    // Sunshine -> Melton, Victoria
    $matrix = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['company_uuid' => 'company-1']);

    expect($matrix->distance)->toBe(31520.0)
        ->and($matrix->time)->toBe(1610.0)
        ->and($requests)->toHaveCount(1)
        ->and((string) $requests[0]->url())->toBe('https://valhalla.routing.fleetbase.test/route')
        ->and($requests[0]->header('X-Api-Key'))->toBe(['test-valhalla-key'])
        ->and($requests[0]->data())->toBe([
            'locations'       => [['lat' => -37.78, 'lon' => 144.83], ['lat' => -37.68, 'lon' => 144.58]],
            'costing'         => 'auto',
            'units'           => 'kilometers',
            'directions_type' => 'none',
            'shape_format'    => 'polyline6',
        ])
        ->and($redis->sets)->toHaveCount(1)
        ->and($redis->sets[0][0])->toStartWith('valhalla:')
        ->and(array_slice($redis->sets[0], 2))->toBe(['EX', Utils::DISTANCE_MATRIX_CACHE_TTL]);

    // The cached result answers the same pair without another request
    $cached = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['company_uuid' => 'company-1']);
    expect($cached->distance)->toBe(31520.0)
        ->and($requests)->toHaveCount(1);
});

test('valhalla failures fall back to the straight-line estimate', function () {
    fleetopsDistanceProviderBoot();
    config(['fleetops.valhalla.host' => 'https://valhalla.routing.fleetbase.test']);
    $log      = $GLOBALS['fleetopsDistanceProviderLog'];
    $redis    = $GLOBALS['fleetopsDistanceProviderRedis'];
    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);

    $responses = [
        Http::response(['error_code' => 442, 'error' => 'No path could be found for input'], 400),
        Http::response(['trip' => ['status' => 442]]),
        Http::response(['trip' => ['status' => 0, 'summary' => ['length' => 0, 'time' => 0]]]),
    ];
    $headers = [];
    Http::fake(function ($request) use (&$responses, &$headers) {
        $headers[] = $request->header('X-Api-Key');

        return array_shift($responses);
    });

    foreach (range(1, 3) as $attempt) {
        $matrix = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'valhalla']);
        expect($matrix->distance)->toBe($estimate->distance)
            ->and($matrix->time)->toBe($estimate->time);
    }

    expect($log->warnings)->toHaveCount(3)
        ->and($log->warnings[0][1]['provider'])->toBe('valhalla')
        ->and($log->warnings[0][1]['error'])->toBe('Valhalla request failed with status 400: No path could be found for input')
        ->and($log->warnings[1][1]['error'])->toBe('Valhalla returned no route.')
        ->and($log->warnings[2][1]['error'])->toBe('no route returned')
        // No key configured, so none is sent
        ->and($headers[0])->toBe([])
        ->and($redis->sets)->toBe([]);
});

test('an unsupported provider name falls back to the straight-line estimate', function () {
    fleetopsDistanceProviderBoot();
    $log = $GLOBALS['fleetopsDistanceProviderLog'];
    Http::fake(['*' => Http::response([], 500)]);

    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);
    $matrix   = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'valhala']);

    expect($matrix->distance)->toBe($estimate->distance)
        ->and($log->warnings[0][1]['error'])->toBe('Unsupported distance matrix provider: valhala');
});

test('company OSRM selection never sends server traffic to the public demo server', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);

    foreach (['https://router.project-osrm.org', 'https://ROUTER.project-osrm.org/', null, 'not a url'] as $host) {
        config(['fleetops.osrm.host' => $host]);
        expect(RoutingEngineResolver::hasPrivateOsrmHost())->toBeFalse()
            ->and(RoutingEngineResolver::resolve('company-1'))->toBe('calculate');
    }

    // Only an explicit per-call provider (the estimations command's --provider) can reach the demo server
    expect(RoutingEngineResolver::trackingProvider('company-1'))->toBe('calculated');
});

test('an unreadable settings store falls back to the default routing engine', function () {
    $connection = fleetopsDistanceProviderBoot();
    $connection->getSchemaBuilder()->drop('settings');

    expect(RoutingEngineResolver::companyEngine('company-1'))->toBeNull()
        ->and(RoutingEngineResolver::resolve('company-1'))->toBe('osrm');
});

test('order distance follows the company routing engine and caches the road result', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);
    $redis = $GLOBALS['fleetopsDistanceProviderRedis'];

    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = (string) $request->url();

        return Http::response(['code' => 'Ok', 'routes' => [['distance' => 31520.4, 'duration' => 1610.2]]]);
    });

    // Sunshine -> Melton, Victoria
    $matrix = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['company_uuid' => 'company-1']);

    expect($matrix->distance)->toBe(31520.4)
        ->and($matrix->time)->toBe(1610.2)
        ->and($requests)->toHaveCount(1)
        ->and($requests[0])->toStartWith('https://osrm.fleetbase.test/route/v1/driving/144.83,-37.78;144.58,-37.68')
        ->and($redis->sets)->toHaveCount(1)
        ->and(array_slice($redis->sets[0], 2))->toBe(['EX', Utils::DISTANCE_MATRIX_CACHE_TTL])
        ->and($GLOBALS['fleetopsDistanceProviderLog']->warnings)->toBe([]);

    // Without a company option the session company decides
    session(['company' => 'company-1']);
    $fromSession = Utils::getDrivingDistanceAndTime([-37.70, 144.70], [-37.68, 144.58]);
    expect($fromSession->distance)->toBe(31520.4)
        ->and($requests)->toHaveCount(2);
});

test('a road provider without a route falls back to the straight-line estimate and logs it', function () {
    fleetopsDistanceProviderBoot();
    $redis = $GLOBALS['fleetopsDistanceProviderRedis'];
    $log   = $GLOBALS['fleetopsDistanceProviderLog'];
    Http::fake(['*' => Http::response(['code' => 'NoRoute', 'routes' => []])]);

    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);
    $matrix   = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'osrm']);

    expect($estimate->distance)->toBeGreaterThan(20000)
        ->and($matrix->distance)->toBe($estimate->distance)
        ->and($matrix->time)->toBe($estimate->time)
        // Empty results are not cached, so the next run asks the provider again
        ->and($redis->sets)->toBe([])
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0][0])->toBe('Road distance unavailable, using the straight-line estimate instead.')
        ->and($log->warnings[0][1]['provider'])->toBe('osrm')
        ->and($log->warnings[0][1]['error'])->toBe('no route returned');
});

test('a failing google request falls back to the straight-line estimate and logs the error', function () {
    fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderPolicy(['providers' => ['google' => true]]);
    $log = $GLOBALS['fleetopsDistanceProviderLog'];
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);
    $matrix   = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'google']);

    expect($matrix->distance)->toBe($estimate->distance)
        ->and($matrix->time)->toBe($estimate->time)
        ->and($log->warnings[0][1]['provider'])->toBe('google')
        ->and($log->warnings[0][1]['error'])->toBe('cURL error 28: Operation timed out');

    // A google response with a zero element is treated as no route
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*' => Http::response(['rows' => [['elements' => [['status' => 'ZERO_RESULTS']]]]])]);
    $zero = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'google']);
    expect($zero->distance)->toBe($estimate->distance)
        ->and($log->warnings)->toHaveCount(2);
});

test('straight-line and same-point lookups never call a road provider', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response(['code' => 'Ok', 'routes' => [['distance' => 1.0, 'duration' => 1.0]]]);
    });

    $samePoint  = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.78, 144.83], ['company_uuid' => 'company-1']);
    $calculated = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'calculate']);

    // With no company and no explicit provider the public demo default stays straight-line
    config(['fleetops.osrm.host' => 'https://router.project-osrm.org']);
    $default = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);

    expect($samePoint->distance)->toBe(0.0)
        ->and($samePoint->time)->toBe(0.0)
        ->and($calculated->time)->toBe(round($calculated->distance / 100) * Utils::DRIVING_TIME_MULTIPLIER)
        ->and($default->distance)->toBe($calculated->distance)
        ->and($requests)->toBe(0);
});

test('the routing provider policy normalizes, saves and enforces provider access', function () {
    $connection = fleetopsDistanceProviderBoot();

    // Defaults: everything self-hosted is available, Google is not
    expect(RoutingProviderPolicy::settings())->toBe([
        'providers'      => ['google' => false, 'valhalla' => true, 'osrm' => true, 'vroom' => true],
        'defaults'       => ['display_engine' => 'osrm', 'optimization_engine' => 'osrm'],
        'server_routing' => 'road',
        'exceptions'     => [],
    ])
        ->and(RoutingProviderPolicy::allowedProviders('company-1'))->toBe(['valhalla', 'osrm', 'vroom'])
        // Feature keys map to their provider, straight-line and third-party providers are always allowed
        ->and(RoutingProviderPolicy::provider('Google_Routes'))->toBe('google')
        ->and(RoutingProviderPolicy::provider(''))->toBeNull()
        ->and(RoutingProviderPolicy::allows('google_routes', 'company-1'))->toBeFalse()
        ->and(RoutingProviderPolicy::allows('calculated'))->toBeTrue()
        ->and(RoutingProviderPolicy::allows('calculate'))->toBeTrue()
        ->and(RoutingProviderPolicy::allows('greedy'))->toBeTrue()
        ->and(RoutingProviderPolicy::allows(null))->toBeTrue();

    $saved = RoutingProviderPolicy::save([
        'providers'  => ['google' => 'false', 'valhalla' => '0', 'osrm' => true],
        'defaults'   => ['display_engine' => 'valhalla', 'optimization_engine' => 'vroom'],
        'exceptions' => [
            ['company_uuid' => 'company-1', 'company_name' => 'Acme', 'providers' => ['google_routes', 'valhalla', 'mapbox']],
            ['company_uuid' => 'company-1', 'providers' => ['osrm']],
            ['company_uuid' => '', 'providers' => ['google']],
            ['company_uuid' => 'company-2', 'providers' => []],
        ],
    ]);

    expect($saved)->toBe([
        'providers'      => ['google' => false, 'valhalla' => false, 'osrm' => true, 'vroom' => true],
        'defaults'       => ['display_engine' => 'valhalla', 'optimization_engine' => 'vroom'],
        'server_routing' => 'road',
        'exceptions'     => [['company_uuid' => 'company-1', 'company_name' => 'Acme', 'providers' => ['google', 'valhalla']]],
    ])
        ->and(json_decode($connection->table('settings')->where('key', RoutingProviderPolicy::SETTING_KEY)->value('value'), true))->toBe($saved)
        // Saving clears the cache, so the change applies at once
        ->and(RoutingProviderPolicy::settings())->toBe($saved)
        ->and(RoutingProviderPolicy::defaultDisplayEngine())->toBe('valhalla')
        ->and(RoutingProviderPolicy::defaultOptimizationEngine())->toBe('vroom')
        ->and(RoutingProviderPolicy::allowedProviders('company-1'))->toBe(['google', 'valhalla', 'osrm', 'vroom'])
        ->and(RoutingProviderPolicy::allowedProviders('company-2'))->toBe(['osrm', 'vroom'])
        ->and(RoutingProviderPolicy::allowedProviders(null))->toBe(['osrm', 'vroom']);

    // Engine fallback: own choice, then the platform default, then OSRM
    expect(RoutingProviderPolicy::engineFor('google', 'osrm', 'company-1'))->toBe('google')
        ->and(RoutingProviderPolicy::engineFor('google', 'vroom', 'company-2'))->toBe('vroom')
        ->and(RoutingProviderPolicy::engineFor(null, 'valhalla', 'company-2'))->toBe('valhalla')
        ->and(RoutingProviderPolicy::engineFor('mapbox', 'osrm', 'company-2'))->toBe('mapbox');

    // A broken settings store falls back to the defaults
    $connection->getSchemaBuilder()->drop('settings');
    Cache::forget(RoutingProviderPolicy::CACHE_KEY);
    expect(RoutingProviderPolicy::settings()['providers']['google'])->toBeFalse();
});

test('a provider switched off by the administrator is never called for order distance', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'valhalla']);
    config(['fleetops.valhalla.host' => 'https://valhalla.routing.fleetbase.test']);
    $log      = $GLOBALS['fleetopsDistanceProviderLog'];
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response(['trip' => ['status' => 0, 'summary' => ['length' => 31.5, 'time' => 1600]]]);
    });

    fleetopsDistanceProviderPolicy(['providers' => ['valhalla' => false, 'osrm' => false]]);
    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);

    expect(RoutingEngineResolver::resolve('company-1'))->toBe('calculate')
        ->and(Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['company_uuid' => 'company-1'])->distance)->toBe($estimate->distance);

    // An explicit request (the estimations command's --provider) is refused too
    $explicit = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'valhalla', 'company_uuid' => 'company-1']);
    expect($explicit->distance)->toBe($estimate->distance)
        ->and($requests)->toBe(0)
        ->and($log->warnings[0][1]['error'])->toBe('The valhalla provider is switched off for this organization.');
});

test('valhalla uses the extension admin settings, an organization override and then the environment', function () {
    $connection = fleetopsDistanceProviderBoot();
    config(['fleetops.valhalla.host' => 'https://env-valhalla.fleetbase.test', 'fleetops.valhalla.api_key' => 'env-key']);

    expect(ValhallaClient::connection('company-1'))->toBe(['host' => 'https://env-valhalla.fleetbase.test', 'api_key' => 'env-key']);

    // The system setting saved from the Valhalla extension's admin panel wins over the environment
    $connection->table('settings')->insert(['key' => 'valhalla', 'value' => json_encode(['api_host' => 'https://valhalla.routing.fleetbase.test/', 'api_key' => 'system-key'])]);
    // An organization pointing at a public community server is ignored
    $connection->table('settings')->insert(['key' => 'company.company-2.valhalla', 'value' => json_encode(['api_host' => 'https://valhalla1.openstreetmap.de', 'api_key' => null])]);
    // An organization with its own server uses it, with its own key
    $connection->table('settings')->insert(['key' => 'company.company-3.valhalla', 'value' => json_encode(['api_host' => 'https://own.valhalla.test', 'api_key' => ''])]);
    Cache::flush();

    expect(ValhallaClient::connection('company-1'))->toBe(['host' => 'https://valhalla.routing.fleetbase.test', 'api_key' => 'system-key'])
        ->and(ValhallaClient::connection('company-2'))->toBe(['host' => 'https://valhalla.routing.fleetbase.test', 'api_key' => 'system-key'])
        ->and(ValhallaClient::connection('company-3'))->toBe(['host' => 'https://own.valhalla.test', 'api_key' => null])
        ->and(ValhallaClient::connection(null))->toBe(['host' => 'https://valhalla.routing.fleetbase.test', 'api_key' => 'system-key']);

    $sent = null;
    Http::fake(function ($request) use (&$sent) {
        $sent = $request;

        return Http::response(['trip' => ['status' => 0, 'summary' => ['length' => 1.0, 'time' => 60.0]]]);
    });
    ValhallaClient::route([new Fleetbase\LaravelMysqlSpatial\Types\Point(-37.78, 144.83), new Fleetbase\LaravelMysqlSpatial\Types\Point(-37.68, 144.58)], [], 'company-3');
    expect((string) $sent->url())->toBe('https://own.valhalla.test/route')
        ->and($sent->header('X-Api-Key'))->toBe([]);

    // Nothing usable anywhere: the client refuses rather than calling a public server
    $connection->getSchemaBuilder()->drop('settings');
    config(['fleetops.valhalla.host' => 'https://valhalla2.openstreetmap.de']);
    Cache::flush();
    expect(ValhallaClient::connection('company-1'))->toBeNull()
        ->and(fn () => ValhallaClient::route([]))->toThrow(RuntimeException::class, 'No Valhalla server is configured.');
});

test('valhalla ignores an organization host the server must not call and uses the system server', function (string $host) {
    $connection = fleetopsDistanceProviderBoot();
    $connection->table('settings')->insert(['key' => 'valhalla', 'value' => json_encode(['api_host' => 'https://valhalla.routing.fleetbase.test', 'api_key' => 'system-key'])]);
    $connection->table('settings')->insert(['key' => 'company.company-1.valhalla', 'value' => json_encode(['api_host' => $host, 'api_key' => 'org-key'])]);
    Cache::flush();

    expect(ValhallaClient::connection('company-1'))->toBe(['host' => 'https://valhalla.routing.fleetbase.test', 'api_key' => 'system-key']);
})->with([
    'cloud metadata'      => 'http://169.254.169.254/latest/meta-data',
    'loopback'            => 'http://127.0.0.1:8002',
    'resolves to private' => 'https://internal.valhalla.test',
    'file scheme'         => 'file:///etc/passwd',
]);

test('valhalla allows private organization hosts when the install opts in', function () {
    $connection = fleetopsDistanceProviderBoot();
    $connection->table('settings')->insert(['key' => 'company.company-1.valhalla', 'value' => json_encode(['api_host' => 'http://10.0.0.12:8002', 'api_key' => 'org-key'])]);
    config(['fleetops.valhalla.allow_private_hosts' => true]);
    Cache::flush();

    expect(ValhallaClient::connection('company-1'))->toBe(['host' => 'http://10.0.0.12:8002', 'api_key' => 'org-key']);
});

test('the orchestrator falls back to the built-in engine when vroom is switched off', function () {
    $connection = fleetopsDistanceProviderBoot();
    $connection->table('settings')->insert(['key' => 'company.company-1.' . Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings::KEY, 'value' => json_encode(['allocation_engine' => 'vroom'])]);

    expect(Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings::engineForCompany('company-1'))->toBe('vroom');

    fleetopsDistanceProviderPolicy(['providers' => ['vroom' => false]]);
    expect(Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings::engineForCompany('company-1'))->toBe(Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings::DEFAULT_ENGINE);

    // An exception keeps VROOM for that organization
    fleetopsDistanceProviderPolicy(['providers' => ['vroom' => false], 'exceptions' => [['company_uuid' => 'company-1', 'providers' => ['vroom']]]]);
    expect(Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings::engineForCompany('company-1'))->toBe('vroom');
});

test('the setting controller saves the routing policy and scopes it to the session company', function () {
    fleetopsDistanceProviderBoot();
    $controller = new class extends Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController {
        public function __construct()
        {
        }

        public function callHelper(string $method, ...$arguments)
        {
            return $this->{$method}(...$arguments);
        }
    };

    session(['company' => 'company-9']);
    expect($controller->callHelper('policyCompanyUuid'))->toBe('company-9');

    $saved = $controller->callHelper('saveRoutingProviderPolicy', ['providers' => ['google' => true]]);
    expect($saved['providers']['google'])->toBeTrue()
        ->and(RoutingProviderPolicy::allows('google_routes', 'company-9'))->toBeTrue();
});

test('osrm is never a fallback and never reaches the public demo server', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-osrm', ['display_engine' => 'osrm']);
    config(['fleetops.routing.display_engine' => 'valhalla']);
    Cache::flush();
    $log      = $GLOBALS['fleetopsDistanceProviderLog'];
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response(['code' => 'Ok', 'routes' => [['distance' => 31520.0, 'duration' => 1610.0]]]);
    });

    // A private OSRM host serves only organizations that chose OSRM; Valhalla is the default and the only fallback
    expect(RoutingEngineResolver::resolve('company-osrm'))->toBe('osrm')
        ->and(RoutingEngineResolver::resolve('company-unsaved'))->toBe('calculate');

    // An explicit OSRM request with only the demo server configured falls back to the estimate
    config(['fleetops.osrm.host' => 'https://router.project-osrm.org']);
    $estimate = Utils::calculateDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58]);
    $matrix   = Utils::getDrivingDistanceAndTime([-37.78, 144.83], [-37.68, 144.58], ['provider' => 'osrm']);

    expect($matrix->distance)->toBe($estimate->distance)
        ->and($requests)->toBe(0)
        ->and($log->warnings[0][1]['error'])->toBe('No private OSRM host is configured.');
});
