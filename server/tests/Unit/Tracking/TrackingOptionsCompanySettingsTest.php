<?php

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderTracker;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers company tracking settings resolution for TrackingOptions: the
 * order's company is used when there is no company session (queued jobs,
 * console commands), and the session company remains the fallback.
 */
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

    $connection->table('settings')->insert([
        ['key' => 'company.company-a.tracking', 'value' => json_encode(['provider' => 'osrm', 'cache_ttl_seconds' => 15])],
        ['key' => 'company.company-b.tracking', 'value' => json_encode(['provider' => 'calculated', 'cache_ttl_seconds' => 45])],
    ]);

    session(['company' => null]);

    return $connection;
}

test('tracking options read the given company settings without a company session', function () {
    fleetopsTrackingOptionsBoot();

    $options = TrackingOptions::fromArray([], 'company-a');

    expect($options->provider)->toBe('osrm')
        ->and($options->cacheTtlSeconds)->toBe(15);

    // Explicit options still win over company settings
    expect(TrackingOptions::fromArray(['provider' => 'google'], 'company-a')->provider)->toBe('google');
});

test('tracking options fall back to the session company when no company is given', function () {
    fleetopsTrackingOptionsBoot();
    session(['company' => 'company-b']);

    $options = TrackingOptions::fromArray([]);
    session(['company' => null]);

    expect($options->provider)->toBe('calculated')
        ->and($options->cacheTtlSeconds)->toBe(45);
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
        ->and($service->received->provider)->toBe('osrm');
});
