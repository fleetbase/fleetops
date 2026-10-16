<?php

use Fleetbase\FleetOps\Support\Routing\DistanceMatrixProviderResolver;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Client\ConnectionException;
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $connection->getSchemaBuilder()->create('settings', function ($blueprint) {
        $blueprint->increments('id');
        $blueprint->string('key')->nullable();
        $blueprint->text('value')->nullable();
        $blueprint->timestamps();
    });

    config([
        'fleetops.distance_matrix.provider' => null,
        'fleetops.osrm.host'                => 'https://osrm.fleetbase.test',
        'services.google_maps.api_key'      => null,
    ]);

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

afterEach(function () {
    config([
        'fleetops.distance_matrix.provider' => null,
        'fleetops.osrm.host'                => null,
        'services.google_maps.api_key'      => null,
    ]);
    app()->forgetInstance('log');
    Log::clearResolvedInstance('log');
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    session(['company' => null]);
});

test('an explicit DISTANCE_MATRIX_PROVIDER wins over the company routing engine', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);

    config(['fleetops.distance_matrix.provider' => 'google']);
    expect(DistanceMatrixProviderResolver::resolve('company-1'))->toBe('google');

    // `calculate` is the operator's kill switch back to straight-line estimates
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    expect(DistanceMatrixProviderResolver::resolve('company-1'))->toBe('calculate');
});

test('the company routing engine is used when the server can call it', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-osrm', ['router' => 'osrm', 'display_engine' => 'osrm']);
    fleetopsDistanceProviderRouting($connection, 'company-google', ['display_engine' => 'google']);
    fleetopsDistanceProviderRouting($connection, 'company-legacy', ['router' => 'google']);
    fleetopsDistanceProviderRouting($connection, 'company-valhalla', ['display_engine' => 'valhalla']);

    // A company that never saved routing settings uses OSRM, as the map does
    expect(DistanceMatrixProviderResolver::resolve('company-osrm'))->toBe('osrm')
        ->and(DistanceMatrixProviderResolver::resolve('company-unsaved'))->toBe('osrm')
        ->and(DistanceMatrixProviderResolver::resolve(null))->toBe('osrm');

    // Google needs a Maps API key on the server
    expect(DistanceMatrixProviderResolver::resolve('company-google'))->toBe('calculate');
    config(['services.google_maps.api_key' => 'test-maps-key']);
    expect(DistanceMatrixProviderResolver::resolve('company-google'))->toBe('google')
        ->and(DistanceMatrixProviderResolver::resolve('company-legacy'))->toBe('google')
        ->and(DistanceMatrixProviderResolver::googleMapsApiKey())->toBe('test-maps-key');

    // Engines the server cannot call fall back to the straight-line estimate
    expect(DistanceMatrixProviderResolver::fromRoutingSettings('company-valhalla'))->toBeNull()
        ->and(DistanceMatrixProviderResolver::resolve('company-valhalla'))->toBe('calculate')
        ->and(DistanceMatrixProviderResolver::isAvailable('valhalla'))->toBeFalse();
});

test('company OSRM selection never sends server traffic to the public demo server', function () {
    $connection = fleetopsDistanceProviderBoot();
    fleetopsDistanceProviderRouting($connection, 'company-1', ['display_engine' => 'osrm']);

    foreach (['https://router.project-osrm.org', 'https://ROUTER.project-osrm.org/', null, 'not a url'] as $host) {
        config(['fleetops.osrm.host' => $host]);
        expect(DistanceMatrixProviderResolver::hasPrivateOsrmHost())->toBeFalse()
            ->and(DistanceMatrixProviderResolver::resolve('company-1'))->toBe('calculate');
    }

    // The operator can still opt into the demo server explicitly
    config(['fleetops.distance_matrix.provider' => 'osrm']);
    expect(DistanceMatrixProviderResolver::resolve('company-1'))->toBe('osrm');
});

test('an unreadable settings store falls back to the default routing engine', function () {
    $connection = fleetopsDistanceProviderBoot();
    $connection->getSchemaBuilder()->drop('settings');

    expect(DistanceMatrixProviderResolver::fromRoutingSettings('company-1'))->toBe('osrm')
        ->and(DistanceMatrixProviderResolver::resolve('company-1'))->toBe('osrm');
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
