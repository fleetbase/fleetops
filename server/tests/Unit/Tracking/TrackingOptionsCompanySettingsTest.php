<?php

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderTracker;
use Fleetbase\FleetOps\Support\Routing\HostPolicy;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers company resolution for TrackingOptions: the order's company is used
 * when there is no company session (queued jobs, console commands), and the
 * session company remains the fallback. The provider is the company's routing
 * engine; the company's tracking settings only tune behaviour.
 */
beforeEach(function () {
    // The example organization Valhalla host resolves to a public address.
    HostPolicy::resolveHostsUsing(fn () => ['93.184.216.34']);
});

afterEach(function () {
    HostPolicy::resolveHostsUsing(null);
});

function fleetopsTrackingOptionsBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $connection->getSchemaBuilder()->create('settings', function ($blueprint) {
        $blueprint->increments('id');
        $blueprint->string('key')->nullable();
        $blueprint->text('value')->nullable();
        $blueprint->timestamps();
    });

    // Company A routes with its own Valhalla server; company B has no routing engine the server can call.
    // A provider saved in tracking settings before tracking followed the routing engine is ignored.
    $connection->table('settings')->insert([
        ['key' => 'company.company-a.routing', 'value' => json_encode(['display_engine' => 'valhalla'])],
        ['key' => 'company.company-a.valhalla', 'value' => json_encode(['api_host' => 'https://company-a.valhalla.test'])],
        ['key' => 'company.company-a.tracking', 'value' => json_encode(['provider' => 'osrm', 'cache_ttl_seconds' => 15])],
        ['key' => 'company.company-b.tracking', 'value' => json_encode(['provider' => 'google_routes', 'cache_ttl_seconds' => 45])],
    ]);

    config(['fleetops.osrm.host' => null, 'fleetops.valhalla.host' => null]);
    Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    session(['company' => null]);

    return $connection;
}

test('tracking options read the given company settings regardless of session', function (?string $sessionCompany) {
    fleetopsTrackingOptionsBoot();
    session(['company' => $sessionCompany]);

    $options = TrackingOptions::fromArray([], 'company-a');

    expect($options->provider)->toBe('valhalla')
        ->and($options->fallbacks)->toBe([])
        ->and($options->cacheTtlSeconds)->toBe(15);

    // An explicitly requested provider (API callers) still wins; the routing policy guards it later
    expect(TrackingOptions::fromArray(['provider' => 'google'], 'company-a')->provider)->toBe('google');
})->with([null, 'company-b']);

test('tracking options fall back to the session company when no company is given', function () {
    fleetopsTrackingOptionsBoot();
    session(['company' => 'company-b']);

    $options = TrackingOptions::fromArray([]);
    session(['company' => null]);

    expect($options->provider)->toBe('calculated')
        ->and($options->cacheTtlSeconds)->toBe(45);
});

test('tracking uses the straight-line estimate when the administrator switches server-side routing off', function () {
    fleetopsTrackingOptionsBoot();
    Fleetbase\FleetOps\Support\Routing\RoutingProviderPolicy::save(['server_routing' => 'straight_line']);

    expect(TrackingOptions::fromArray([], 'company-a')->provider)->toBe('calculated');
});

test('order tracker builds options from the order company', function () {
    fleetopsTrackingOptionsBoot();

    $service = new class extends TrackingIntelligenceService {
        public ?TrackingOptions $received = null;

        public function __construct()
        {
        }

        public function track(Order $order, array|TrackingOptions $options = []): array
        {
            $this->received = $options;

            return [];
        }
    };
    app()->instance(TrackingIntelligenceService::class, $service);

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-tracking-options', 'company_uuid' => 'company-a'], true);

    (new OrderTracker($order))->toArray();
    app()->forgetInstance(TrackingIntelligenceService::class);

    expect($service->received)->toBeInstanceOf(TrackingOptions::class)
        ->and($service->received->provider)->toBe('valhalla');
});

test('tracking remains available with configured defaults when the settings database is unavailable', function () {
    $connection = fleetopsTrackingOptionsBoot();
    $connection->getSchemaBuilder()->drop('settings');
    $original = config('fleetops.tracking', []);
    try {
        config(['fleetops.tracking' => ['cache_ttl_seconds' => 17]]);
        $options = TrackingOptions::fromArray([], 'uncached-unavailable-company');
        expect($options->provider)->toBe('calculated')->and($options->cacheTtlSeconds)->toBe(17);
        expect(TrackingOptions::fromArray(['provider' => 'osrm'], 'uncached-unavailable-company')->provider)->toBe('osrm');
    } finally {
        config(['fleetops.tracking' => $original]);
    }
});
