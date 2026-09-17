<?php

require_once __DIR__ . '/../../Support/ExampleTelemetryProvider.php';

use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops:sync-telematics command with the process lock skipped:
 * pollable-provider filtering by request/discovery/webhook flags, the
 * no-provider early exit, and the chunked job queueing over active
 * telematics connections.
 */
class FleetOpsSyncTelematicsProbe extends SyncTelematics
{
    public array $messages = [];
    public array $options  = ['no-lock' => true, 'provider' => [], 'limit' => 500, 'exclude-webhook-providers' => false];

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    public function option($key = null, $default = null)
    {
        return $this->options[$key] ?? $default;
    }
}

function fleetopsSyncTelematicsRegistry(array $descriptors): TelematicProviderRegistry
{
    $registry = new TelematicProviderRegistry();
    foreach ($descriptors as $descriptor) {
        $registry->register(new TelematicProviderDescriptor($descriptor));
    }

    return $registry;
}

function fleetopsSyncTelematicsBoot(): SQLiteConnection
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

    $schema = $connection->getSchemaBuilder();
    $schema->create('telematics', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'status', 'credentials', 'name'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    DispatchRecorder::$dispatched = [];

    return $connection;
}

test('sync telematics exits early without pollable providers', function () {
    fleetopsSyncTelematicsBoot();

    $command  = new FleetOpsSyncTelematicsProbe();
    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'webhook-only', 'label' => 'Webhook Only', 'supports_discovery' => false],
    ]);

    expect($command->handle($registry))->toBe(0)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});

test('sync telematics queues jobs for active matching connections', function () {
    $connection = fleetopsSyncTelematicsBoot();
    $connection->table('telematics')->insert([
        ['uuid' => 'tm-1', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'active'],
        ['uuid' => 'tm-2', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'connected'],
        ['uuid' => 'tm-3', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'disabled'],
        ['uuid' => 'tm-4', 'company_uuid' => null, 'provider' => 'traccar', 'status' => 'active'],
        ['uuid' => 'tm-5', 'company_uuid' => 'company-1', 'provider' => 'other', 'status' => 'active'],
    ]);

    $command  = new FleetOpsSyncTelematicsProbe();
    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'traccar', 'label' => 'Traccar', 'supports_discovery' => true],
    ]);

    expect($command->handle($registry))->toBe(0)
        ->and(DispatchRecorder::$dispatched)->toHaveCount(2)
        ->and($command->messages)->toContain(['info', 'Queued 2 telematics sync job(s).']);
});

test('sync telematics filters providers by request and webhook flags', function () {
    fleetopsSyncTelematicsBoot();

    $command          = new FleetOpsSyncTelematicsProbe();
    $command->options = array_merge($command->options, [
        'provider'                  => ['traccar'],
        'exclude-webhook-providers' => true,
    ]);

    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'traccar', 'label' => 'Traccar', 'supports_discovery' => true, 'supports_webhooks' => true],
        ['key' => 'samsara', 'label' => 'Samsara', 'supports_discovery' => true],
    ]);

    // traccar is requested but excluded for webhook support; samsara is
    // pollable but not requested — nothing remains.
    expect($command->handle($registry))->toBe(0)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});

test('sync telematics command releases its bounded lock and recovers an interrupted lease', function () {
    fleetopsSyncTelematicsBoot();
    $originalCache = Illuminate\Support\Facades\Cache::getFacadeRoot();
    Illuminate\Support\Carbon::setTestNow('2026-09-17 12:00:00 UTC');

    try {
        $store = new class extends Illuminate\Cache\ArrayStore {
            public array $requestedLocks = [];

            public function lock($name, $seconds = 0, $owner = null)
            {
                $this->requestedLocks[] = [$name, $seconds];

                return parent::lock($name, $seconds, $owner);
            }
        };
        Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository($store));
        $command                     = new FleetOpsSyncTelematicsProbe();
        $command->options['no-lock'] = false;
        $registry                    = fleetopsSyncTelematicsRegistry([]);
        expect($command->handle($registry))->toBe(0);
        expect($store->requestedLocks[0])->toBe(['fleetops:sync-telematics', 120]);
        expect($store->locks)->toBe([]);

        // Leave a lease behind as if the previous command process had stopped.
        [$key, $ttl] = $store->requestedLocks[0];
        expect($store->lock($key, $ttl)->get())->toBeTrue();
        $command->messages = [];
        Illuminate\Support\Carbon::setTestNow('2026-09-17 12:01:59 UTC');
        expect($command->handle($registry))->toBe(0)
            ->and($command->messages)->toContain(['warn', 'Another telematics sync run appears to be in progress.']);

        $command->messages = [];
        Illuminate\Support\Carbon::setTestNow('2026-09-17 12:02:01 UTC');
        expect($command->handle($registry))->toBe(0)
            ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
        expect($store->locks)->toBe([]);
    } finally {
        Illuminate\Support\Facades\Cache::swap($originalCache);
        Illuminate\Support\Carbon::setTestNow();
    }
});

test('sync telematics reports successful durable and legacy dispatches without counting coalesced polls', function () {
    $connection = fleetopsSyncTelematicsBoot();
    $connection->table('telematics')->insert([
        ['uuid' => 'durable-connection', 'company_uuid' => 'company-1', 'provider' => 'example', 'status' => 'active'],
        ['uuid' => 'legacy-connection', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'active'],
    ]);
    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'example', 'label' => 'Example', 'supports_discovery' => true, 'driver_class' => ExampleTelemetryProvider::class, 'metadata' => ['telemetry' => ['durable_ingestion' => true]]],
        ['key' => 'traccar', 'label' => 'Traccar', 'supports_discovery' => true],
    ]);
    $originalCache       = Illuminate\Support\Facades\Cache::getFacadeRoot();
    $originalCacheConfig = config('cache', []);
    $dispatcherContract  = Illuminate\Contracts\Bus\Dispatcher::class;
    $originalDispatcher  = app()->bound($dispatcherContract) ? app($dispatcherContract) : null;

    try {
        config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array']]);
        Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\CacheManager(app()));
        $dispatcher = new class(app()) extends Illuminate\Bus\Dispatcher {
            public array $jobs = [];

            public function dispatch($command)
            {
                $this->jobs[] = $command;

                return $command;
            }
        };
        app()->instance($dispatcherContract, $dispatcher);
        $command = new FleetOpsSyncTelematicsProbe();

        expect($command->handle($registry))->toBe(0)
            ->and($command->messages)->toContain(['info', 'Queued 2 telematics sync job(s).']);
        expect($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(Fleetbase\FleetOps\Jobs\PollTelematicTelemetry::class)
            ->and(DispatchRecorder::$dispatched)->toHaveCount(1);

        // The real unique lock suppresses a second durable poll; legacy dispatch remains eligible.
        $command->messages = [];
        expect($command->handle($registry))->toBe(0)
            ->and($command->messages)->toContain(['info', 'Queued 1 telematics sync job(s).']);
        expect($dispatcher->jobs)->toHaveCount(1)
            ->and(DispatchRecorder::$dispatched)->toHaveCount(2);

        $command->messages            = [];
        $command->options['provider'] = ['example'];
        expect($command->handle($registry))->toBe(0)
            ->and($command->messages)->toContain(['info', 'Queued 0 telematics sync job(s).']);
        expect($dispatcher->jobs)->toHaveCount(1);
    } finally {
        Illuminate\Support\Facades\Cache::swap($originalCache);
        config(['cache' => $originalCacheConfig]);
        if ($originalDispatcher) {
            app()->instance($dispatcherContract, $originalDispatcher);
        } else {
            app()->offsetUnset($dispatcherContract);
        }
    }
});

class PausedBatchTelemetryProvider extends ExampleTelemetryProvider
{
    public function telemetryOptions(): array
    {
        return ['polling_enabled' => false, 'manual_batch_sync' => true];
    }
}

class PausedReconciledTelemetryProvider extends ExampleTelemetryProvider
{
    public function telemetryOptions(): array
    {
        return ['polling_enabled' => false];
    }
}

test('sync telematics pauses batch-only providers instead of queueing legacy syncs that would fail', function () {
    $connection = fleetopsSyncTelematicsBoot();
    $connection->table('telematics')->insert([
        ['uuid' => 'batch-connection', 'company_uuid' => 'company-1', 'provider' => 'batch', 'status' => 'error'],
        ['uuid' => 'reconciled-connection', 'company_uuid' => 'company-1', 'provider' => 'reconciled', 'status' => 'active'],
    ]);
    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'batch', 'label' => 'Batch', 'supports_discovery' => true, 'driver_class' => PausedBatchTelemetryProvider::class, 'metadata' => ['telemetry' => ['durable_ingestion' => true]]],
        ['key' => 'reconciled', 'label' => 'Reconciled', 'supports_discovery' => true, 'driver_class' => PausedReconciledTelemetryProvider::class, 'metadata' => ['telemetry' => ['durable_ingestion' => true]]],
    ]);
    $command = new FleetOpsSyncTelematicsProbe();

    // Providers without batch sync keep their existing legacy reconciliation while polling is paused.
    expect($command->handle($registry))->toBe(0)
        ->and(DispatchRecorder::$dispatched)->toHaveCount(1)
        ->and(DispatchRecorder::$dispatched[0]['arguments'][0]->uuid)->toBe('reconciled-connection')
        ->and($command->messages)->toContain(['info', 'Queued 1 telematics sync job(s).']);
});
