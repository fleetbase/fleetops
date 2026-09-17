<?php

require_once __DIR__ . '/../../Support/TelemetryTestEnvironment.php';
require_once __DIR__ . '/../../Support/ExampleTelemetryProvider.php';

use Fleetbase\FleetOps\Jobs\PollTelematicTelemetry;
use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Ingestor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

if (!function_exists('Fleetbase\\FleetOps\\Support\\Telematics\\Telemetry\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Support\\Telematics\\Telemetry; function broadcast($event) { $GLOBALS["afaqy_broadcasts"][] = $event; }');
}
if (!function_exists('Fleetbase\\FleetOps\\Support\\Telematics\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Support\\Telematics; function broadcast($event) { $GLOBALS["afaqy_broadcasts"][] = $event; }');
}

function manualTelemetryFixture(): Telematic
{
    $pdo      = new PDO('sqlite::memory:');
    $geometry = function ($text, $srid = 0, ...$rest) {
        preg_match('/POINT\(([\d.e+\-]+) ([\d.e+\-]+)\)/i', $text, $coordinates);

        return pack('V', $srid) . chr(1) . pack('V', 1) . pack('e2', (float) $coordinates[1], (float) $coordinates[2]);
    };
    $pdo->sqliteCreateFunction('ST_GeomFromText', $geometry);
    $pdo->sqliteCreateFunction('ST_PointFromText', $geometry);
    $connection = new SQLiteConnection($pdo, '', '', ['name' => 'mysql']);
    $connection->setTransactionManager(new Illuminate\Database\DatabaseTransactionsManager());
    $resolver = new ConnectionResolver(['mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Model::setConnectionResolver($resolver);
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
    app()->instance('db', new class($connection) {
        public function __construct(public $connection)
        {
        }

        public function connection($name = null)
        {
            return $this->connection;
        }

        public function __call($method, $args)
        {
            return $this->connection->{$method}(...$args);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');
    Schema::clearResolvedInstance('db.schema');
    app()->instance('encrypter', new Illuminate\Encryption\Encrypter(str_repeat('m', 32), 'aes-256-cbc'));
    Crypt::clearResolvedInstance('encrypter');
    Cache::flush();
    Carbon::setTestNow('2026-09-15 12:00:00 UTC');
    config(['telematics.afaqy.webhooks_enabled' => true, 'telematics.afaqy.polling_enabled' => true]);
    config(['telematics.providers' => (require __DIR__ . '/../../../config/telematics.php')['providers']]);
    app()->instance(TelematicProviderRegistry::class, new TelematicProviderRegistry());
    $GLOBALS['afaqy_broadcasts'] = [];
    // This persistence fixture excludes model listeners and external transports.
    foreach ([
        'telematics'    => ['uuid', 'public_id', 'company_uuid', 'provider', 'status', 'meta', 'credentials'],
        'vehicles'      => ['uuid', 'public_id', 'company_uuid', 'name', 'plate_number', 'location', 'speed', 'heading', 'altitude', 'odometer', 'online', 'telematics'],
        'sensors'       => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'telematic_uuid', 'type', 'internal_id', 'name', 'unit', 'last_value', 'last_reading_at', 'last_position', 'status', 'meta'],
        'positions'     => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'coordinates', 'speed', 'heading', 'bearing', 'altitude', 'order_uuid', 'destination_uuid'],
        'devices'       => ['uuid', 'public_id', 'company_uuid', 'telematic_uuid', 'device_id', 'internal_id', 'name', 'model', 'provider', 'type', 'imei', 'imsi', 'serial_number', 'firmware_version', 'last_position', 'last_online_at', 'online', 'status', 'meta', 'attachable_uuid', 'attachable_type'],
        'device_events' => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'event_type', 'severity', 'message', 'provider', 'ident', 'code', 'state', 'reason', 'occurred_at', 'data', 'payload', '_key', 'meta', 'location'],
    ] as $table => $columns) {
        Schema::create($table, function ($schema) use ($columns) {
            $schema->increments('id');
            foreach ($columns as $column) {
                $schema->text($column)->nullable();
            }
            $schema->timestamps();
            $schema->timestamp('deleted_at')->nullable();
            $schema->index('uuid');
        });
    }
    (require __DIR__ . '/../../../migrations/2026_09_15_000001_create_telematic_telemetry_tables.php')->up();
    Schema::table('device_events', fn ($table) => $table->index('_key'));
    DB::table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_1', 'company_uuid' => 'company-1', 'provider' => 'example-manual', 'status' => 'active', 'meta' => '{}']);

    return Telematic::withoutGlobalScopes()->first();
}

class ManualTelemetryProvider extends ExampleTelemetryProvider
{
    public array $requests = [];
    public bool $fail      = false;
    public ?int $failOnCall = null;

    public function telemetryOptions(): array
    {
        return array_merge(parent::telemetryOptions(), ['manual_batch_sync' => true, 'batch_size' => 1, 'webhooks_enabled' => false]);
    }

    public function fetchDevices(array $options = []): array
    {
        $this->requests[] = $options;
        if ($this->fail || $this->failOnCall === count($this->requests)) {
            throw new RuntimeException('Provider unavailable');
        }

        return parent::fetchDevices($options);
    }
}

class ManualTelemetryRegistry extends TelematicProviderRegistry
{
    public function __construct(public ManualTelemetryProvider $provider)
    {
    }

    public function resolve(string $key): Fleetbase\FleetOps\Contracts\TelematicProviderInterface
    {
        return $this->provider;
    }
}

function manualTelemetrySetup(): array
{
    $connection = manualTelemetryFixture();
    $provider   = new ManualTelemetryProvider();
    $registry   = new ManualTelemetryRegistry($provider);
    app()->instance(TelematicProviderRegistry::class, $registry);
    config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array']]);
    Cache::swap(new Illuminate\Cache\CacheManager(app()));
    DB::table('devices')->insert(['uuid' => 'device-1', 'public_id' => 'device_1', 'company_uuid' => 'company-1', 'telematic_uuid' => $connection->uuid, 'device_id' => 'unit-1', 'name' => 'Tracker', 'status' => 'offline', 'meta' => '{}']);
    $GLOBALS['manual_telemetry_jobs']          = [];
    $GLOBALS['manual_telemetry_fail_dispatch'] = false;
    $dispatcher                                = new class(app()) extends Illuminate\Bus\Dispatcher {
        public function dispatch($command)
        {
            if ($GLOBALS['manual_telemetry_fail_dispatch']) {
                throw new RuntimeException('Broker unavailable');
            }
            $GLOBALS['manual_telemetry_jobs'][] = $command;

            return null;
        }
    };
    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
    ExampleTelemetryProvider::$connections = 0;
    ExampleTelemetryProvider::$pages       = [];

    return [$connection, $provider, $registry, new TelematicService($registry)];
}

function manualTelemetrySample(string $time = '2026-09-15T11:59:00Z', float $latitude = 24.0): array
{
    return ['tracker' => 'unit-1', 'measured' => $time, 'received' => $time, 'point' => [46.7, $latitude]];
}

function manualTelemetryProcessPending(TelematicService $service): void
{
    foreach (DB::table('telematic_deliveries')->where('status', 'pending')->pluck('uuid') as $id) {
        (new ProcessTelematicDelivery($id))->handle(new Ingestor(), $service);
    }
}

afterEach(function () {
    Carbon::setTestNow();
    unset($GLOBALS['telemetry_test_clock']);
});

test('manual telemetry sync queues once and completes only after durable ingestion', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $id                                           = $service->discoverDevices($connection, ['filters' => ['group' => 4], 'limit' => 2]);
    expect($service->discoverDevices($connection))->toBe($id);
    expect(count($GLOBALS['manual_telemetry_jobs']))->toBe(1);
    expect(ExampleTelemetryProvider::$connections)->toBe(0);
    $job = $GLOBALS['manual_telemetry_jobs'][0];
    expect($job)->toBeInstanceOf(PollTelematicTelemetry::class)->and($job->queue)->toBe('default')->and($job->timeout)->toBeLessThan(90);
    expect(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('queued');
    ExampleTelemetryProvider::$pages = [['devices' => [manualTelemetrySample()], 'has_more' => false, 'next_cursor' => null]];
    $job->handle($registry, new Inbox());
    expect($provider->requests[0]['filters'])->toBe(['group' => 4])->and($provider->requests[0]['refresh_inventory'])->toBeTrue();
    expect(data_get($connection->fresh()->meta, 'last_sync_phase'))->toBe('ingesting');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(0);
    manualTelemetryProcessPending($service);
    $fresh = $connection->fresh();
    expect($fresh->status)->toBe('active')->and(data_get($fresh->meta, 'last_sync_result'))->toBe('success')
        ->and(data_get($fresh->meta, 'last_sync_total'))->toBe(1)->and(data_get($fresh->meta, 'last_sync_failed_total'))->toBe(0);
});

test('old queued discovery jobs delegate without entering their legacy lock or HTTP work', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $lock                                         = Cache::lock('fleetops:sync-telematic-devices:' . $connection->uuid, 3660);
    $lock->get();
    try {
        (new SyncTelematicDevicesJob($connection, [], 'old-job'))->handle($registry, $service);
        expect(count($GLOBALS['manual_telemetry_jobs']))->toBe(1)
            ->and($GLOBALS['manual_telemetry_jobs'][0]->manualJobId)->toBe('old-job')
            ->and(ExampleTelemetryProvider::$connections)->toBe(0);
    } finally {
        $lock->release();
    }
});

test('old queued discovery jobs finish quietly when a scheduled poll already holds the request', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    expect(Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue::dispatch(new PollTelematicTelemetry($connection->uuid)))->toBeTrue();
    (new SyncTelematicDevicesJob($connection, [], 'old-job'))->handle($registry, $service);
    $fresh = $connection->fresh();
    // A thrown ValidationException would fail the tries=1 job and mark this connection as errored.
    expect(count($GLOBALS['manual_telemetry_jobs']))->toBe(1)
        ->and($fresh->status)->toBe('active')
        ->and(data_get($fresh->meta, 'last_sync_job_id'))->toBeNull();
});

test('broker failure leaves the request unqueued and permits a later manual retry', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $GLOBALS['manual_telemetry_fail_dispatch']    = true;
    expect(fn () => $service->discoverDevices($connection))->toThrow(RuntimeException::class, 'Broker unavailable');
    expect($connection->fresh()->status)->toBe('active')->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBeNull();
    $GLOBALS['manual_telemetry_fail_dispatch'] = false;
    $service->discoverDevices($connection);
    expect(count($GLOBALS['manual_telemetry_jobs']))->toBe(1)->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('queued');
});

test('disabled or tenantless connections cannot start manual or scheduled polling', function ($attributes) {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    DB::table('telematics')->update($attributes);
    $connection->refresh();
    expect(fn () => $service->discoverDevices($connection))->toThrow(ValidationException::class);
    (new PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
    expect(count($GLOBALS['manual_telemetry_jobs']))->toBe(0)->and(ExampleTelemetryProvider::$connections)->toBe(0);
})->with([[['status' => 'disabled']], [['company_uuid' => null]]]);

test('manual fetch failures remain retryable and successful retry finishes the matching request', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $service->discoverDevices($connection);
    $job            = $GLOBALS['manual_telemetry_jobs'][0];
    $provider->fail = true;
    expect(fn () => $job->handle($registry, new Inbox()))->toThrow(RuntimeException::class);
    expect(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('retrying');
    $job->failed(new RuntimeException('Exhausted'));
    expect($connection->fresh()->status)->toBe('error');
    $provider->fail                  = false;
    ExampleTelemetryProvider::$pages = [['devices' => [manualTelemetrySample()], 'has_more' => false, 'next_cursor' => null]];
    $job->handle($registry, new Inbox());
    manualTelemetryProcessPending($service);
    expect($connection->fresh()->status)->toBe('active')->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('success');
});

test('older delivery completion cannot finalize a newer manual request', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $service->discoverDevices($connection);
    ExampleTelemetryProvider::$pages = [['devices' => [manualTelemetrySample()], 'has_more' => false, 'next_cursor' => null]];
    $GLOBALS['manual_telemetry_jobs'][0]->handle($registry, new Inbox());
    $fresh       = $connection->fresh();
    $fresh->meta = array_merge($fresh->meta, ['last_sync_job_id' => 'new-request', 'last_sync_result' => 'queued']);
    $fresh->save();
    manualTelemetryProcessPending($service);
    expect(data_get($connection->fresh()->meta, 'last_sync_job_id'))->toBe('new-request')
        ->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('queued');
});

test('scheduled reconciliation deduplicates current samples and retains newest position', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    foreach ([manualTelemetrySample(), manualTelemetrySample(), manualTelemetrySample('2026-09-15T11:40:00Z', 10)] as $sample) {
        ExampleTelemetryProvider::$pages = [['devices' => [$sample], 'has_more' => false, 'next_cursor' => null]];
        (new PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
        manualTelemetryProcessPending($service);
    }
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(2)
        ->and(Device::withoutGlobalScopes()->first()->last_position->getLat())->toBe(24.0);
});

test('polling budget includes durable inbox writes and never extends to the Redis reservation', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    ExampleTelemetryProvider::$pages              = [['devices' => [manualTelemetrySample()], 'has_more' => false, 'next_cursor' => null]];
    $GLOBALS['telemetry_test_clock']              = [1000.0, 1000.0, 1001.0, 1001.0, 1060.0];
    expect(fn () => (new PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox()))->toThrow(RuntimeException::class, 'time budget');
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('incomplete');
    expect(DB::table('telematic_deliveries')->count())->toBe(1);
});

class ManualPollingQueueJob extends Illuminate\Queue\Jobs\SyncJob
{
    public int $reservations = 1;
    public int $releases = 0;
    public int $failures = 0;
    public ?PollTelematicTelemetry $command = null;

    public function attempts()
    {
        return $this->reservations;
    }

    public function release($delay = 0)
    {
        $this->releases++;
    }

    public function fail($error = null)
    {
        $this->failures++;
        $this->command?->failed($error);
    }
}

function manualPollingWorker(): Illuminate\Queue\Worker
{
    $reflection = new ReflectionClass(Illuminate\Queue\Worker::class);
    $worker = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('cache')->setValue($worker, Cache::store());

    return $worker;
}

function manualPollingQueuePayload(PollTelematicTelemetry $job): ManualPollingQueueJob
{
    $queueJob = new ManualPollingQueueJob(app(), json_encode([
        'uuid' => 'manual-poll-queue-job', 'maxTries' => $job->tries,
        'maxExceptions' => $job->maxExceptions, 'retryUntil' => $job->retryUntil()->getTimestamp(),
    ]), 'test', 'default');
    $queueJob->command = $job;
    $job->setJob($queueJob);

    return $queueJob;
}

test('healthy inbox waiting can exceed five reservations without exhausting provider retries', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $service->discoverDevices($connection);
    $job = $GLOBALS['manual_telemetry_jobs'][0];
    $queueJob = manualPollingQueuePayload($job);
    (new Inbox())->accept($connection, [manualTelemetrySample()], 'poll');
    $worker = manualPollingWorker();
    $check = new ReflectionMethod($worker, 'markJobAsFailedIfAlreadyExceedsMaxAttempts');
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $queueJob->reservations = $attempt;
        $check->invoke($worker, 'test', $queueJob, 1);
        $job->handle($registry, new Inbox());
        Carbon::setTestNow(now()->addSeconds(15));
    }
    expect($queueJob->releases)->toBe(10)->and($queueJob->failures)->toBe(0)
        ->and(ExampleTelemetryProvider::$connections)->toBe(0)
        ->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('retrying');
});

test('five actual provider exceptions fail the manual request independently of waiting reservations', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $service->discoverDevices($connection);
    $job = $GLOBALS['manual_telemetry_jobs'][0];
    $queueJob = manualPollingQueuePayload($job);
    $worker = manualPollingWorker();
    $check = new ReflectionMethod($worker, 'markJobAsFailedIfWillExceedMaxExceptions');
    $provider->fail = true;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        expect(fn () => $job->handle($registry, new Inbox()))->toThrow(RuntimeException::class, 'Provider unavailable');
        $check->invoke($worker, 'test', $queueJob, new RuntimeException('Provider unavailable'));
        expect($queueJob->failures)->toBe($attempt === 5 ? 1 : 0);
    }
    expect($connection->fresh()->status)->toBe('error')
        ->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('failed');
});

test('absolute retry deadline survives serialization and stops stalled requests', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $service->discoverDevices($connection);
    $job = unserialize(serialize($GLOBALS['manual_telemetry_jobs'][0]));
    $deadline = $job->retryUntil()->getTimestamp();
    $queueJob = manualPollingQueuePayload($job);
    Carbon::setTestNow(now()->addMinutes(16));
    expect($job->retryUntil()->getTimestamp())->toBe($deadline);
    $job->handle($registry, new Inbox());
    expect($queueJob->failures)->toBe(1)->and(ExampleTelemetryProvider::$connections)->toBe(0)
        ->and(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('failed');
});

test('legacy serialized poll jobs share a fixed fallback retry deadline', function () {
    [$connection] = manualTelemetrySetup();
    $job = new PollTelematicTelemetry($connection->uuid);
    $job->retryDeadline = null;
    $deadline = $job->retryUntil()->getTimestamp();
    Carbon::setTestNow(now()->addMinutes(5));
    $restored = new PollTelematicTelemetry($connection->uuid);
    $restored->retryDeadline = null;
    expect($restored->retryUntil()->getTimestamp())->toBe($deadline);
});

test('incomplete sweep deliveries do not finish a manual request awaiting provider retry', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $id = $service->discoverDevices($connection);
    ExampleTelemetryProvider::$pages = [['devices' => [manualTelemetrySample()], 'has_more' => true, 'next_cursor' => 1]];
    $provider->failOnCall = 2;
    $job = $GLOBALS['manual_telemetry_jobs'][0];
    expect(fn () => $job->handle($registry, new Inbox()))->toThrow(RuntimeException::class);
    manualTelemetryProcessPending($service);
    expect(data_get($connection->fresh()->meta, 'last_sync_result'))->toBe('retrying');
    expect($service->discoverDevices($connection->fresh()))->toBe($id);
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('incomplete');
});

test('expired scheduled polls stop without provider work when no queue job is attached', function () {
    [$connection, $provider, $registry] = manualTelemetrySetup();
    $job = new PollTelematicTelemetry($connection->uuid);
    Carbon::setTestNow(now()->addMinutes(16));
    $job->handle($registry, new Inbox());
    // Scheduled polls have no manual request to fail; the next tick schedules a fresh attempt.
    expect(ExampleTelemetryProvider::$connections)->toBe(0)
        ->and(DB::table('telematic_sync_runs')->count())->toBe(0)
        ->and($connection->fresh()->status)->toBe('active');
});

test('manual polls release instead of dropping the request while another sweep holds the poll lock', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $service->discoverDevices($connection);
    $job      = $GLOBALS['manual_telemetry_jobs'][0];
    $queueJob = manualPollingQueuePayload($job);
    $lock     = Cache::lock('telemetry:poll:' . $connection->uuid, 85);
    expect($lock->get())->toBeTrue();
    try {
        $job->handle($registry, new Inbox());
    } finally {
        $lock->release();
    }
    expect($queueJob->releases)->toBe(1)->and(ExampleTelemetryProvider::$connections)->toBe(0)
        ->and(DB::table('telematic_sync_runs')->count())->toBe(0);
});

test('superseded manual polls neither report progress nor fail the newer request', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    $current = $service->discoverDevices($connection);
    $stale = new PollTelematicTelemetry($connection->uuid, 'superseded-job');
    ExampleTelemetryProvider::$pages = [['devices' => [manualTelemetrySample()], 'has_more' => false, 'next_cursor' => null]];
    $stale->handle($registry, new Inbox());
    $stale->failed(new RuntimeException('Provider unavailable'));
    $fresh = $connection->fresh();
    expect(data_get($fresh->meta, 'last_sync_job_id'))->toBe($current)
        ->and(data_get($fresh->meta, 'last_sync_result'))->toBe('queued')
        ->and(data_get($fresh->meta, 'last_sync_run_uuid'))->toBeNull()
        ->and($fresh->status)->toBe('synchronizing');
});

test('manual sync re-checks the locked connection before queueing', function () {
    [$connection, $provider, $registry, $service] = manualTelemetrySetup();
    // The caller's model is stale: the connection was disabled after it was loaded.
    DB::table('telematics')->update(['status' => 'disabled']);
    expect(fn () => $service->queueTelemetrySync($connection))->toThrow(ValidationException::class);
    expect(count($GLOBALS['manual_telemetry_jobs']))->toBe(0)
        ->and(DB::table('telematics')->value('status'))->toBe('disabled');
});
