<?php

require_once __DIR__ . '/../../Support/AfaqyTestCrypto.php';
require_once __DIR__ . '/../../Support/ExampleTelemetryProvider.php';
require_once __DIR__ . '/../../Support/TelemetryTestEnvironment.php';

use Fleetbase\FleetOps\Http\Controllers\TelematicPositionWebhookController;
use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Ingestor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!function_exists('Fleetbase\\FleetOps\\Support\\Telematics\\Telemetry\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Support\\Telematics\\Telemetry; function broadcast($event) { $GLOBALS["afaqy_broadcasts"][] = $event; }');
}
if (!function_exists('Fleetbase\\FleetOps\\Support\\Telematics\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Support\\Telematics; function broadcast($event) { $GLOBALS["afaqy_broadcasts"][] = $event; }');
}

function afaqyDbFixture(): Telematic
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
    app()->instance('encrypter', afaqyTestCrypto());
    Crypt::clearResolvedInstance('encrypter');
    Cache::flush();
    Carbon::setTestNow('2026-09-15 12:00:00 UTC');
    config(['telematics.afaqy.webhooks_enabled' => true, 'telematics.afaqy.polling_enabled' => true]);
    config(['telematics.providers' => (require __DIR__ . '/../../../config/telematics.php')['providers']]);
    app()->instance(TelematicProviderRegistry::class, new TelematicProviderRegistry());
    $GLOBALS['afaqy_broadcasts'] = [];
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
    DB::table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_1', 'company_uuid' => 'company-1', 'provider' => 'afaqy', 'status' => 'active', 'meta' => '{}']);
    DB::table('devices')->insert(['uuid' => 'device-1', 'public_id' => 'device_1', 'telematic_uuid' => 'integration-1', 'company_uuid' => 'company-1', 'device_id' => 'unit-1', 'name' => 'Original name', 'status' => 'offline', 'meta' => '{"preserved":"yes"}']);

    return Telematic::withoutGlobalScopes()->first();
}

function afaqyDbUnit(string $time = '2026-09-15 11:59:00', float $lat = 24.0): array
{
    return ['_id' => 'unit-1', 'last_update' => ['dtt' => $time, 'dts' => $time, 'lat' => $lat, 'lng' => 46.7, 'spd' => 20, 'acc' => 1]];
}

afterEach(fn () => Carbon::setTestNow());

test('database ingestion deduplicates poll and push and never regresses device state', function () {
    $telematic = afaqyDbFixture();
    $service   = new TelematicService(new TelematicProviderRegistry());
    $ingestor  = new Ingestor();
    $provider  = new AfaqyProvider();
    $ingestor->ingest($telematic, $provider, afaqyDbUnit(), $service, '2026-09-15 12:00:00', 'poll');
    $again = $ingestor->ingest($telematic, $provider, afaqyDbUnit(), $service, '2026-09-15 12:00:00', 'webhook');
    expect($again['duplicate'])->toBeTrue();
    $ingestor->ingest($telematic, $provider, afaqyDbUnit('2026-09-15 11:50:00', 10), $service);
    $device = Device::withoutGlobalScopes()->first();
    expect(data_get($device->meta, 'telemetry.position_at'))->toBe('2026-09-15T11:59:00.000000Z');
    expect($device->name)->toBe('Original name');
    expect(data_get($device->meta, 'preserved'))->toBe('yes');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(2);
    expect(count($GLOBALS['afaqy_broadcasts']))->toBe(1);
    expect($device->last_position->getLat())->toBe(24.0);
});

test('webhook authenticates before durable acceptance and worker quarantines unknown shapes', function () {
    $telematic = afaqyDbFixture();
    DB::table('telematic_webhook_credentials')->insert(['telematic_uuid' => $telematic->uuid, 'token' => Crypt::encryptString('secret')]);
    $controller = new TelematicPositionWebhookController();
    $inbox      = new Inbox();
    $request    = fn ($token, $body) => Request::create('/?telematic=telematic_1&key=' . $token, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    expect($controller->handle($request('wrong', afaqyDbUnit()), $inbox, 'afaqy')->getStatusCode())->toBe(403);
    expect(DB::table('telematic_deliveries')->count())->toBe(0);
    $response = $controller->handle($request('secret', ['unexpected' => 'event']), $inbox, 'afaqy');
    expect($response->getStatusCode())->toBe(200);
    $id = $response->getData(true)['delivery_id'];
    expect(DB::table('telematic_deliveries')->value('payload'))->not->toContain('unexpected');
    (new ProcessTelematicDelivery($id))->handle(new Ingestor(), new TelematicService(new TelematicProviderRegistry()));
    expect(DB::table('telematic_deliveries')->value('status'))->toBe('quarantined');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(0);
});

test('durable pending delivery survives broker failure and duplicate worker execution', function () {
    $telematic = afaqyDbFixture();
    $id        = (new Inbox())->accept($telematic, [afaqyDbUnit()], 'webhook');
    $service   = new TelematicService(new TelematicProviderRegistry());
    $job       = new ProcessTelematicDelivery($id);
    $job->handle(new Ingestor(), $service);
    $job->handle(new Ingestor(), $service);
    expect(DB::table('telematic_deliveries')->where('uuid', $id)->value('status'))->toBe('processed');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(1);
});

test('attached vehicle keeps the latest position across delayed samples and broadcasts to its company', function () {
    $telematic = afaqyDbFixture();
    DB::table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_1', 'company_uuid' => 'company-1', 'name' => 'Truck', 'telematics' => '{}']);
    DB::table('devices')->update(['attachable_uuid' => 'vehicle-1', 'attachable_type' => Fleetbase\FleetOps\Models\Vehicle::class]);
    $ingestor = new Ingestor();
    $service  = new TelematicService(new TelematicProviderRegistry());
    $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit(), $service);
    $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit('2026-09-15 11:30:00', 12), $service);
    $vehicle = Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->first();
    expect($vehicle->location->getLat())->toBe(24.0);
    expect(DB::table('devices')->value('attachable_uuid'))->toBe('vehicle-1');
    $broadcast = array_values(array_filter($GLOBALS['afaqy_broadcasts'], fn ($event) => $event instanceof Fleetbase\FleetOps\Events\VehicleLocationChanged))[0];
    expect($broadcast->broadcastOn()[0]->name)->toBe('company.company-1');
});

class AfaqyPollingFixtureProvider extends AfaqyProvider
{
    public array $responses = [];

    public function connect(Telematic $telematic): void
    {
    }

    public function fetchDevices(array $options = []): array
    {
        return array_shift($this->responses);
    }
}
class AfaqyPollingFixtureRegistry extends TelematicProviderRegistry
{
    public function __construct(public AfaqyPollingFixtureProvider $provider)
    {
    }

    public function resolve(string $key): Fleetbase\FleetOps\Contracts\TelematicProviderInterface
    {
        return $this->provider;
    }
}

test('poll sweep recovers error connections and coalesces while ingestion is pending', function () {
    $telematic = afaqyDbFixture();
    DB::table('telematics')->update(['status' => 'error']);
    $provider            = new AfaqyPollingFixtureProvider();
    $provider->responses = [['devices' => [afaqyDbUnit()], 'has_more' => false, 'next_cursor' => null]];
    $job                 = new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($telematic->uuid);
    $registry            = new AfaqyPollingFixtureRegistry($provider);
    $job->handle($registry, new Inbox());
    $job->handle($registry, new Inbox());
    expect(DB::table('telematic_sync_runs')->count())->toBe(1);
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('ingesting');
    $delivery = DB::table('telematic_deliveries')->value('uuid');
    (new ProcessTelematicDelivery($delivery))->handle(new Ingestor(), new TelematicService(new TelematicProviderRegistry()));
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('completed');
    expect(DB::table('telematic_sync_runs')->value('applied'))->toBe(1);
});

test('repeated pages are explicitly incomplete and disabled connections are not polled', function () {
    $telematic           = afaqyDbFixture();
    $provider            = new AfaqyPollingFixtureProvider();
    $provider->responses = [
        ['devices' => [afaqyDbUnit()], 'has_more' => true, 'next_cursor' => 1],
        ['devices' => [afaqyDbUnit()], 'has_more' => false, 'next_cursor' => null],
    ];
    $job = new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($telematic->uuid);
    expect(fn () => $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox()))->toThrow(RuntimeException::class, 'Repeated');
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('incomplete');
    DB::table('telematics')->update(['status' => 'disabled']);
    $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox());
    expect(DB::table('telematic_sync_runs')->count())->toBe(1);
});

test('invalid coordinates do not overwrite a stored position and cross-tenant identity is isolated', function () {
    $telematic = afaqyDbFixture();
    $service   = new TelematicService(new TelematicProviderRegistry());
    $ingestor  = new Ingestor();
    $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit(), $service);
    $result = $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit('2026-09-15 11:59:30', 999), $service);
    expect($result['invalid_position'])->toBeTrue();
    expect(Device::withoutGlobalScopes()->first()->last_position->getLat())->toBe(24.0);
    DB::table('devices')->insert(['uuid' => 'device-2', 'public_id' => 'device_2', 'company_uuid' => 'company-2', 'telematic_uuid' => 'integration-2', 'device_id' => 'unit-1', 'name' => 'Other company', 'meta' => '{}']);
    $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit('2026-09-15 11:59:40', 25), $service);
    expect(DB::table('devices')->where('uuid', 'device-2')->value('last_online_at'))->toBeNull();
});

test('failed event writes roll back device changes and can be replayed without partial state', function () {
    $telematic = afaqyDbFixture();
    $service   = new class(new TelematicProviderRegistry()) extends TelematicService {
        public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
        {
            throw new RuntimeException('Injected database write failure.');
        }
    };
    $ingestor = new Ingestor();
    expect(fn () => $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit(), $service))->toThrow(RuntimeException::class);
    expect(DB::table('devices')->value('last_online_at'))->toBeNull();
    expect(DB::table('devices')->value('last_position'))->toBeNull();
    expect($GLOBALS['afaqy_broadcasts'])->toBe([]);
    $ingestor->ingest($telematic, new AfaqyProvider(), afaqyDbUnit(), new TelematicService(new TelematicProviderRegistry()));
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(1);
});

test('five thousand units and a duplicate reconciliation sweep fit the local processing budget', function () {
    $telematic = afaqyDbFixture();
    DB::table('devices')->delete();
    $rows = [];
    for ($i = 0; $i < 5000; $i++) {
        $rows[] = ['uuid' => 'device-' . $i, 'public_id' => 'device_' . $i, 'company_uuid' => 'company-1', 'telematic_uuid' => 'integration-1', 'device_id' => 'unit-' . $i, 'name' => 'Truck ' . $i, 'meta' => '{}'];
    }
    foreach (array_chunk($rows, 100) as $batch) {
        DB::table('devices')->insert($batch);
    }
    $ingestor = new Ingestor();
    $service  = new TelematicService(new TelematicProviderRegistry());
    $provider = new AfaqyProvider();
    $start    = microtime(true);
    foreach (['webhook', 'poll'] as $source) {
        for ($i = 0; $i < 5000; $i++) {
            $unit        = afaqyDbUnit();
            $unit['_id'] = 'unit-' . $i;
            $ingestor->ingest($telematic, $provider, $unit, $service, '2026-09-15 12:00:00', $source);
        }
    }
    $elapsed = microtime(true) - $start;
    fwrite(STDERR, sprintf("AFAQY local SQLite benchmark: 5000 signals + 5000 duplicates in %.3fs (%.1f observations/s)\n", $elapsed, 10000 / $elapsed));
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(5000);
    expect($elapsed)->toBeLessThan(60.0);
})->skip(getenv('AFAQY_RUN_LOAD_TESTS') !== '1', 'Opt-in local processing benchmark; production latency requires deployment testing.');

test('inbox maintenance retains replayable payloads and cleans expired rows during a broker outage', function () {
    $telematic   = afaqyDbFixture();
    $inbox       = new Inbox();
    $pending     = $inbox->accept($telematic, afaqyDbUnit(), 'poll');
    $processed   = $inbox->accept($telematic, afaqyDbUnit(), 'webhook');
    $quarantined = $inbox->accept($telematic, ['event' => 'unknown'], 'webhook');
    DB::table('telematic_deliveries')->where('uuid', $processed)->update(['status' => 'processed', 'updated_at' => now()->subHours(25)]);
    DB::table('telematic_deliveries')->where('uuid', $quarantined)->update(['status' => 'quarantined', 'updated_at' => now()->subDays(2)]);
    config(['telematics.afaqy.webhooks_enabled' => false]);
    // A broken third-party registration must not block other inboxes or retention.
    app(TelematicProviderRegistry::class)->register(new Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor([
        'key'      => 'unavailable', 'label' => 'Unavailable', 'driver_class' => 'MissingTelemetryDriver',
        'metadata' => ['telemetry' => ['durable_ingestion' => true]],
    ]));
    expect((new Fleetbase\FleetOps\Console\Commands\DrainTelematicInbox())->handle())->toBe(0);
    expect(DB::table('telematic_deliveries')->where('uuid', $processed)->exists())->toBeFalse();
    expect(DB::table('telematic_deliveries')->where('uuid', $quarantined)->exists())->toBeTrue();
    expect(DB::table('telematic_deliveries')->where('uuid', $pending)->value('status'))->toBe('pending');
});

test('sensor values use source time and preserve units through partial and older snapshots', function () {
    $telematic = afaqyDbFixture();
    DB::table('sensors')->insert(['uuid' => 'sensor-1', 'telematic_uuid' => 'integration-1', 'company_uuid' => 'company-1', 'device_uuid' => 'device-1', 'type' => 'fuel', 'internal_id' => 'afaqy:unit-1:fuel', 'name' => 'Fuel', 'unit' => 'L', 'last_value' => '50', 'last_reading_at' => '2026-09-15 11:50:00']);
    $unit            = afaqyDbUnit();
    $unit['sensors'] = ['fuel' => 40];
    $ingestor        = new Ingestor();
    $service         = new TelematicService(new TelematicProviderRegistry());
    $ingestor->ingest($telematic, new AfaqyProvider(), $unit, $service);
    expect(DB::table('sensors')->where('uuid', 'sensor-1')->value('last_value'))->toBe('40');
    expect(DB::table('sensors')->where('uuid', 'sensor-1')->value('unit'))->toBe('L');
    $unit['last_update']['dtt'] = '2026-09-15 11:45:00';
    $unit['sensors']            = ['fuel' => 70];
    $ingestor->ingest($telematic, new AfaqyProvider(), $unit, $service);
    expect(DB::table('sensors')->count())->toBe(1);
    expect(DB::table('sensors')->where('uuid', 'sensor-1')->value('last_value'))->toBe('40');
});

test('a second registered adapter uses shared polling inbox ingestion and tenant scoped webhooks', function () {
    $connection           = afaqyDbFixture();
    $connection->provider = 'example';
    $connection->save();
    $registry = app(TelematicProviderRegistry::class);
    $registry->register(new Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor([
        'key'               => 'example', 'label' => 'Example', 'driver_class' => ExampleTelemetryProvider::class,
        'supports_webhooks' => true, 'supports_discovery' => true,
        'metadata'          => ['telemetry' => ['durable_ingestion' => true, 'secure_webhooks' => true, 'reconciliation' => true]],
    ]));
    $signal                                = ['tracker' => 'unit-1', 'measured' => '2026-09-15T11:59:00Z', 'received' => '2026-09-15T11:59:10Z', 'point' => [12.0, 50.0]];
    ExampleTelemetryProvider::$connections = 0;
    ExampleTelemetryProvider::$pages       = [['devices' => [$signal], 'has_more' => false, 'next_cursor' => null]];
    (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
    $id = DB::table('telematic_deliveries')->value('uuid');
    expect($id)->not->toBeNull();
    $service = new TelematicService($registry);
    (new ProcessTelematicDelivery($id))->handle(new Ingestor(), $service);
    expect(ExampleTelemetryProvider::$connections)->toBe(1);
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('completed');
    $device = Device::withoutGlobalScopes()->first();
    expect($device->last_position->getLat())->toBe(50.0);
    expect(data_get($device->meta, 'telemetry.stale_after_seconds'))->toBe(90);
    expect(data_get($device->meta, 'telemetry.provider_at'))->toBe('2026-09-15T11:59:10.000000Z');
    DB::table('telematic_webhook_credentials')->insert(['telematic_uuid' => $connection->uuid, 'token' => Crypt::encryptString('example-secret')]);
    $request    = Request::create('/?telematic=telematic_1&key=example-secret', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['signals' => [$signal]]));
    $controller = new TelematicPositionWebhookController();
    expect($controller->handle($request, new Inbox(), 'afaqy')->getStatusCode())->toBe(403);
    $response = $controller->handle($request, new Inbox(), 'example');
    expect($response->getStatusCode())->toBe(200);
    (new ProcessTelematicDelivery($response->getData(true)['delivery_id']))->handle(new Ingestor(), $service);
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(1);
    expect(count($GLOBALS['afaqy_broadcasts']))->toBe(1);
    expect(ExampleTelemetryProvider::$connections)->toBe(1);
    expect(Schema::hasTable('afaqy_deliveries'))->toBeFalse();
});

test('shared polling accepts opaque cursors from another provider and schema rollback preserves core tables', function () {
    $connection           = afaqyDbFixture();
    $connection->provider = 'example';
    $connection->save();
    $registry = app(TelematicProviderRegistry::class);
    $registry->register(new Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor(['key' => 'example', 'label' => 'Example', 'driver_class' => ExampleTelemetryProvider::class]));
    $signal                          = ['tracker' => 'unit-1', 'measured' => '2026-09-15T11:59:00Z', 'received' => '2026-09-15T11:59:10Z', 'point' => [12, 50]];
    ExampleTelemetryProvider::$pages = [
        ['devices' => [$signal], 'has_more' => true, 'next_cursor' => 'cursor-z'],
        ['devices' => [array_replace($signal, ['tracker' => 'unit-2'])], 'has_more' => false, 'next_cursor' => null],
    ];
    (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
    expect(DB::table('telematic_sync_runs')->value('pages'))->toBe(2);
    expect(DB::table('telematic_sync_runs')->value('units'))->toBe(2);
    expect(DB::table('telematic_deliveries')->count())->toBe(2);
    $migration = require __DIR__ . '/../../../migrations/2026_09_15_000001_create_telematic_telemetry_tables.php';
    $migration->down();
    expect(Schema::hasTable('telematic_deliveries'))->toBeFalse();
    expect(DB::table('devices')->count())->toBe(1);
    $migration->up();
    expect(Schema::hasTable('telematic_webhook_credentials'))->toBeTrue();
    expect(Schema::hasTable('afaqy_webhook_tokens'))->toBeFalse();
});

class AfaqyRecordingDispatcher extends Illuminate\Bus\Dispatcher
{
    public array $jobs       = [];
    public bool $unavailable = false;

    public function dispatch($command)
    {
        if ($this->unavailable) {
            throw new RuntimeException('Broker unavailable');
        }

        $this->jobs[] = $command;

        return $command;
    }
}

function afaqyQueueFixture(): AfaqyRecordingDispatcher
{
    config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array']]);
    Cache::swap(new Illuminate\Cache\CacheManager(app()));
    $dispatcher = new AfaqyRecordingDispatcher(app());
    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);

    return $dispatcher;
}

test('telemetry dispatch coalesces duplicate jobs and releases uniqueness after broker failure', function () {
    afaqyDbFixture();
    $dispatcher = afaqyQueueFixture();
    $job        = new ProcessTelematicDelivery('delivery-unique');
    expect($job->uniqueId())->toBe('delivery-unique');
    expect(Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue::dispatch($job))->toBeTrue();
    expect(Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue::dispatch($job))->toBeFalse();
    expect($dispatcher->jobs)->toBe([$job]);

    $dispatcher->unavailable = true;
    $retry                   = new ProcessTelematicDelivery('delivery-retry');
    expect(fn () => Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue::dispatch($retry))->toThrow(RuntimeException::class, 'Broker unavailable');
    $dispatcher->unavailable = false;
    expect(Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue::dispatch($retry))->toBeTrue();
    expect($dispatcher->jobs)->toBe([$job, $retry]);
});

test('device telemetry broadcasts contain only the device snapshot and tenant scoped channels', function () {
    afaqyDbFixture();
    $device       = Device::withoutGlobalScopes()->first();
    $device->meta = ['telemetry' => ['position_at' => '2026-09-15T11:59:00Z'], 'private' => 'not broadcast'];
    $event        = new Fleetbase\FleetOps\Events\DeviceTelemetryUpdated($device);
    expect(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))->toBe(['company.company-1', 'device.device-1']);
    expect($event->afterCommit)->toBeTrue();
    expect($event->broadcastAs())->toBe('device.telemetry_updated');
    expect($event->broadcastWith())->toBe([
        'event' => 'device.telemetry_updated',
        'data'  => ['id' => 'device-1', 'device_id' => 'device_1', 'telemetry' => ['position_at' => '2026-09-15T11:59:00Z']],
    ]);
});

test('position webhook rejects malformed requests and backpressure without accepting a delivery', function () {
    $connection = afaqyDbFixture();
    DB::table('telematic_webhook_credentials')->insert(['telematic_uuid' => $connection->uuid, 'token' => Crypt::encryptString('secret')]);
    $controller = new TelematicPositionWebhookController();
    $inbox      = new Inbox();
    $request    = fn ($body) => Request::create('/?telematic=telematic_1&key=secret', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    expect($controller->handle(Request::create('/', 'GET'), $inbox, 'afaqy')->getStatusCode())->toBe(405);
    expect($controller->handle(Request::create('/', 'POST'), $inbox, 'afaqy')->getStatusCode())->toBe(403);
    config(['telematics.afaqy.webhooks_enabled' => false]);
    expect($controller->handle($request('{}'), $inbox, 'afaqy')->getStatusCode())->toBe(503);
    config(['telematics.afaqy.webhooks_enabled' => true, 'telematics.afaqy.max_payload_bytes' => 10]);
    expect($controller->handle($request(str_repeat('x', 11)), $inbox, 'afaqy')->getStatusCode())->toBe(413);
    config(['telematics.afaqy.max_payload_bytes' => 2097152]);
    foreach (['invalid JSON', '42', 'null'] as $body) {
        expect($controller->handle($request($body), $inbox, 'afaqy')->getStatusCode())->toBe(422);
    }
    config(['telematics.afaqy.max_pending_deliveries' => 0]);
    expect($controller->handle($request('{}'), $inbox, 'afaqy')->getStatusCode())->toBe(503);
    config(['telematics.afaqy.max_pending_deliveries' => 10000]);
    $failedInbox = new class extends Inbox {
        public function accept(Telematic $telematic, array $payload, string $source, ?string $run = null, ?string $receivedAt = null): string
        {
            throw new RuntimeException('Database unavailable');
        }
    };
    expect($controller->handle($request('{}'), $failedInbox, 'afaqy')->getData(true))->toBe(['error' => 'Unable to persist delivery; retry required.']);
    expect(DB::table('telematic_deliveries')->count())->toBe(0);
});

test('delivery worker leaves leased and paused work untouched and quarantines exhausted or removed connections', function () {
    $connection = afaqyDbFixture();
    $inbox      = new Inbox();
    $id         = $inbox->accept($connection, afaqyDbUnit(), 'webhook');
    $job        = new ProcessTelematicDelivery($id);
    $ingestor   = new Ingestor();
    $service    = new TelematicService(new TelematicProviderRegistry());
    $lock       = Cache::lock('telemetry:delivery:' . $id, 90);
    expect($lock->get())->toBeTrue();
    $job->handle($ingestor, $service);
    expect(DB::table('telematic_deliveries')->value('attempts'))->toBe(0);
    $lock->release();
    config(['telematics.afaqy.webhooks_enabled' => false]);
    $job->handle($ingestor, $service);
    expect(DB::table('telematic_deliveries')->value('status'))->toBe('pending');
    config(['telematics.afaqy.webhooks_enabled' => true]);
    DB::table('telematic_deliveries')->update(['attempts' => 5]);
    $job->handle($ingestor, $service);
    expect(DB::table('telematic_deliveries')->value('status'))->toBe('quarantined');
    expect(DB::table('telematic_deliveries')->value('error'))->toContain('retry budget exhausted');
    $removed = $inbox->accept($connection, afaqyDbUnit(), 'poll');
    DB::table('telematics')->delete();
    (new ProcessTelematicDelivery($removed))->handle($ingestor, $service);
    expect(DB::table('telematic_deliveries')->where('uuid', $removed)->value('error'))->toBe('Connection disabled or removed.');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(0);
});

test('delivery worker retries only failed units and quarantines invalid positions after retries', function () {
    $connection = afaqyDbFixture();
    $service    = new TelematicService(new TelematicProviderRegistry());
    $good       = afaqyDbUnit();
    $bad        = afaqyDbUnit('2026-09-15 11:59:30', 999);
    $failing    = array_replace($good, ['_id' => 'retry-unit']);
    $id         = (new Inbox())->accept($connection, [$good, $bad, $failing], 'poll');
    $ingestor   = new class extends Ingestor {
        public function ingest(Telematic $telematic, Fleetbase\FleetOps\Contracts\TelemetryProviderInterface $provider, array $raw, TelematicService $service, ?string $receivedAt = null, string $source = 'poll'): array
        {
            if ($raw['_id'] === 'retry-unit') {
                throw new RuntimeException('Temporary unit failure');
            }

            return parent::ingest($telematic, $provider, $raw, $service, $receivedAt, $source);
        }
    };
    $job = new ProcessTelematicDelivery($id);
    $job->handle($ingestor, $service);
    $row = DB::table('telematic_deliveries')->where('uuid', $id)->first();
    expect($row->status)->toBe('retry');
    expect($row->applied)->toBe(1);
    expect($row->invalid_count)->toBe(1);
    expect(json_decode(Crypt::decryptString($row->retry_payload), true))->toEqual([$failing]);
    expect(Carbon::parse($row->available_at)->timestamp)->toBe(now()->addSeconds(15)->timestamp);
    DB::table('telematic_deliveries')->where('uuid', $id)->update(['attempts' => 4, 'available_at' => now()]);
    $job->handle($ingestor, $service);
    $row = DB::table('telematic_deliveries')->where('uuid', $id)->first();
    expect($row->status)->toBe('quarantined');
    expect($row->failed)->toBe(2);
    expect($row->applied)->toBe(1);
    expect($row->processed_at)->not->toBeNull();
});

test('shared service routes telemetry adapters through ordered ingestion and preserves a newer vehicle position', function () {
    $connection = afaqyDbFixture();
    DB::table('vehicles')->insert(['uuid' => 'vehicle-newer', 'public_id' => 'vehicle_newer', 'company_uuid' => 'company-1', 'name' => 'Truck', 'telematics' => json_encode(['last_event_at' => '2026-09-15T12:00:00Z'])]);
    DB::table('devices')->update(['attachable_uuid' => 'vehicle-newer', 'attachable_type' => Fleetbase\FleetOps\Models\Vehicle::class]);
    $service = new TelematicService(new TelematicProviderRegistry());
    $result  = $service->ingestDeviceSnapshot($connection, new AfaqyProvider(), afaqyDbUnit());
    expect($result['event'])->toBeInstanceOf(DeviceEvent::class);
    expect(DB::table('vehicles')->value('location'))->toBeNull();
    expect(json_decode(DB::table('vehicles')->value('telematics'), true)['last_event_at'])->toBe('2026-09-15T12:00:00Z');
    $webhook = new Fleetbase\FleetOps\Http\Controllers\TelematicWebhookController(new TelematicProviderRegistry(), $service, new Fleetbase\Support\IdempotencyManager());
    expect($webhook->handle(Request::create('/', 'GET'), 'afaqy')->getStatusCode())->toBe(405);
});

test('ingestion rejects missing identities and ignores future contact and sensor timestamps', function () {
    $connection = afaqyDbFixture();
    $service    = new TelematicService(new TelematicProviderRegistry());
    $ingestor   = new Ingestor();
    $provider   = new AfaqyProvider();
    $missing    = afaqyDbUnit();
    unset($missing['_id']);
    expect(fn () => $ingestor->ingest($connection, $provider, $missing, $service))->toThrow(InvalidArgumentException::class, 'Unit identity is required');
    $unit                       = afaqyDbUnit();
    $unit['last_update']['dts'] = '2026-09-16 12:00:00';
    $ingestor->ingest($connection, $provider, $unit, $service);
    expect(Device::withoutGlobalScopes()->first()->last_online_at?->lte(now()) ?? true)->toBeTrue();
    $future            = afaqyDbUnit('2026-09-16 12:00:00');
    $future['_id']     = 'new-invalid-device';
    $future['sensors'] = ['fuel' => 90];
    $result            = $ingestor->ingest($connection, $provider, $future, $service);
    expect($result['invalid_position'])->toBeTrue();
    expect($result['sensors'])->toBe(0);
    expect($result['device']->last_online_at)->toBeNull();
    expect(DB::table('sensors')->count())->toBe(0);
});

test('new GPS fixes keep a later contact watermark and null dated sensors are skipped', function () {
    $connection = afaqyDbFixture();
    DB::table('devices')->update(['last_online_at' => '2026-09-15 12:00:00']);
    $provider = new class extends AfaqyProvider {
        public function normalizeTelemetrySnapshot(array $payload): array
        {
            $snapshot            = parent::normalizeTelemetrySnapshot($payload);
            $snapshot['sensors'] = [['recorded_at' => null]];

            return $snapshot;
        }
    };
    $result = (new Ingestor())->ingest($connection, $provider, afaqyDbUnit(), new TelematicService(new TelematicProviderRegistry()));
    expect($result['device']->last_online_at->toDateTimeString())->toBe('2026-09-15 12:00:00');
    expect($result['sensors'])->toBe(0);
});

test('inbox completion waits for pending deliveries and aggregates quarantined failures', function () {
    $connection = afaqyDbFixture();
    Inbox::finishRun('missing-run');
    expect(DB::table('telematic_sync_runs')->count())->toBe(0);
    DB::table('telematic_sync_runs')->insert(['uuid' => 'run-pending', 'telematic_uuid' => $connection->uuid, 'status' => 'fetching', 'created_at' => now(), 'updated_at' => now()]);
    Inbox::finishRun('run-pending');
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('fetching');
    DB::table('telematic_sync_runs')->update(['status' => 'ingesting']);
    $id = (new Inbox())->accept($connection, afaqyDbUnit(), 'poll', 'run-pending');
    Inbox::finishRun('run-pending');
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('ingesting');
    DB::table('telematic_deliveries')->where('uuid', $id)->update(['status' => 'quarantined', 'applied' => 2, 'failed' => 1]);
    Inbox::finishRun('run-pending');
    expect((array) DB::table('telematic_sync_runs')->first())->toMatchArray(['status' => 'partial', 'applied' => 2, 'failed' => 1]);
});

test('telemetry diagnostics report setup receipt backlog and degraded delivery states', function () {
    $connection = afaqyDbFixture();
    session(['company' => $connection->company_uuid]);
    $registry   = new TelematicProviderRegistry();
    $controller = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\TelematicController(new TelematicService($registry), $registry);
    expect($controller->telemetryDiagnostics($connection->uuid)->getData(true)['webhook_state'])->toBe('not_configured');
    DB::table('telematic_webhook_credentials')->insert(['telematic_uuid' => $connection->uuid, 'token' => Crypt::encryptString('secret')]);
    expect($controller->telemetryDiagnostics($connection->uuid)->getData(true)['webhook_state'])->toBe('awaiting_first_delivery');
    $id          = (new Inbox())->accept($connection, afaqyDbUnit(), 'webhook');
    $diagnostics = $controller->telemetryDiagnostics($connection->uuid)->getData(true);
    expect($diagnostics['webhook_state'])->toBe('receiving');
    expect($diagnostics['delivery_counts'])->toBe(['pending' => 1]);
    expect($diagnostics['last_webhook']['uuid'])->toBe($id);
    expect($diagnostics['last_webhook'])->not->toHaveKeys(['payload', 'retry_payload', 'token']);
    DB::table('telematic_deliveries')->where('uuid', $id)->update(['status' => 'quarantined', 'error' => 'Invalid position', 'failed' => 1]);
    $diagnostics = $controller->telemetryDiagnostics($connection->uuid)->getData(true);
    expect($diagnostics['webhook_state'])->toBe('degraded');
    expect($diagnostics['recent_failures'][0]['uuid'])->toBe($id);
});

test('delivery replay resets failures within the current integration and survives dispatch failure', function () {
    $connection = afaqyDbFixture();
    session(['company' => $connection->company_uuid]);
    $dispatcher = afaqyQueueFixture();
    $registry   = new TelematicProviderRegistry();
    $controller = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\TelematicController(new TelematicService($registry), $registry);
    $id         = (new Inbox())->accept($connection, afaqyDbUnit(), 'poll');
    DB::table('telematic_deliveries')->where('uuid', $id)->update([
        'status'        => 'quarantined', 'attempts' => 5, 'retry_payload' => 'old-retry', 'failed' => 2, 'applied' => 3,
        'invalid_count' => 1, 'error' => 'Old error', 'processed_at' => now(),
    ]);
    (new Illuminate\Bus\UniqueLock(Cache::store()))->release(new ProcessTelematicDelivery($id));
    $dispatcher->unavailable = true;
    expect($controller->replayTelemetryDelivery($connection->uuid, $id)->getStatusCode())->toBe(202);
    $row = DB::table('telematic_deliveries')->where('uuid', $id)->first();
    expect((array) $row)->toMatchArray(['status' => 'pending', 'attempts' => 0, 'retry_payload' => null, 'failed' => 0, 'applied' => 0, 'invalid_count' => 0, 'error' => null, 'processed_at' => null]);
    expect(Crypt::decryptString($row->payload))->toBe(json_encode(afaqyDbUnit()));
    $dispatcher->unavailable = false;
    expect((new Fleetbase\FleetOps\Console\Commands\DrainTelematicInbox())->handle())->toBe(0);
    expect($dispatcher->jobs)->toHaveCount(2);
    expect($dispatcher->jobs[1]->deliveryUuid)->toBe($id);
});

class AfaqySyncCommandProbe extends Fleetbase\FleetOps\Console\Commands\SyncTelematics
{
    public array $messages = [];
    public array $options  = ['no-lock' => false, 'provider' => ['afaqy'], 'limit' => 500, 'exclude-webhook-providers' => true];

    public function option($key = null)
    {
        return $this->options[$key] ?? null;
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = $string;
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = $string;
    }
}

test('scheduled telemetry sync dispatches durable polls and retries broker failures on the next tick', function () {
    $connection = afaqyDbFixture();
    $dispatcher = afaqyQueueFixture();
    $command    = new AfaqySyncCommandProbe();
    $registry   = app(TelematicProviderRegistry::class);
    expect($command->handle($registry))->toBe(0);
    expect($dispatcher->jobs)->toHaveCount(1);
    $job = $dispatcher->jobs[0];
    expect($job)->toBeInstanceOf(Fleetbase\FleetOps\Jobs\PollTelematicTelemetry::class);
    expect($job->uniqueId())->toBe($connection->uuid);
    expect($job->backoff())->toBe([15, 60, 180, 300]);
    expect($job->delay->betweenIncluded(now(), now()->addSeconds(9)))->toBeTrue();
    (new Illuminate\Bus\UniqueLock(Cache::store()))->release($job);
    $dispatcher->unavailable = true;
    expect($command->handle($registry))->toBe(0);
    $dispatcher->unavailable = false;
    expect($command->handle($registry))->toBe(0);
    expect($dispatcher->jobs)->toHaveCount(2);
    config(['telematics.afaqy.polling_enabled' => false]);
    expect($command->handle($registry))->toBe(0);
    expect($dispatcher->jobs)->toHaveCount(2);
    (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
    expect(DB::table('telematic_sync_runs')->count())->toBe(0);
});

test('polling honors connection locks and rejects stalled pagination and page exhaustion', function () {
    $connection = afaqyDbFixture();
    $job        = new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid);
    $provider   = new AfaqyPollingFixtureProvider();
    $registry   = new AfaqyPollingFixtureRegistry($provider);
    $lock       = Cache::lock('telemetry:poll:' . $connection->uuid, 150);
    expect($lock->get())->toBeTrue();
    $job->handle($registry, new Inbox());
    expect(DB::table('telematic_sync_runs')->count())->toBe(0);
    $lock->release();
    $provider->responses = [['devices' => [], 'has_more' => true, 'next_cursor' => null]];
    expect(fn () => $job->handle($registry, new Inbox()))->toThrow(RuntimeException::class, 'Non-advancing pagination');
    config(['telematics.afaqy.max_pages' => 0]);
    expect(fn () => $job->handle($registry, new Inbox()))->toThrow(RuntimeException::class, 'Maximum page count');
    config(['telematics.afaqy.max_pages' => 100]);
    expect(DB::table('telematic_sync_runs')->where('status', 'incomplete')->count())->toBe(2);
});

test('polling releases a rate-limited queue job using the provider retry delay', function () {
    $connection = afaqyDbFixture();
    $provider   = new class extends AfaqyPollingFixtureProvider {
        public function fetchDevices(array $options = []): array
        {
            throw new Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException('Slow down', ['retry_after' => 37]);
        }
    };
    $job      = new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid);
    $queueJob = new class(app(), '{}', 'test', 'default') extends Illuminate\Queue\Jobs\SyncJob {
        public ?int $delay = null;

        public function release($delay = 0)
        {
            $this->delay = $delay;
        }
    };
    $job->setJob($queueJob);
    $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox());
    expect($queueJob->delay)->toBe(37);
    expect(DB::table('telematic_sync_runs')->value('error'))->toBe('Rate limited; retry scheduled.');
});

test('webhook provisioning reuses encrypted credentials and explicit rotation revokes the old URL', function () {
    $connection = afaqyDbFixture();
    session(['company' => $connection->company_uuid]);
    config(['app.env' => 'production']);
    $registry   = new TelematicProviderRegistry();
    $controller = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\TelematicController(new TelematicService($registry), $registry);
    $url        = $controller->telemetryWebhook(new Request(), $connection->uuid)->getData(true)['url'];
    expect($url)->toStartWith('https://api.example.test/webhooks/telematics/afaqy?');
    parse_str(parse_url($url, PHP_URL_QUERY), $credentials);
    expect($credentials['telematic'])->toBe($connection->public_id);
    expect(strlen($credentials['key']))->toBe(64);
    expect(Crypt::decryptString(DB::table('telematic_webhook_credentials')->value('token')))->toBe($credentials['key']);
    expect($controller->telemetryWebhook(new Request(), $connection->uuid)->getData(true)['url'])->toBe($url);
    $rotated = $controller->telemetryWebhook(new Request(['rotate' => true]), $connection->uuid)->getData(true)['url'];
    expect($rotated)->not->toBe($url);
    expect(DB::table('telematic_webhook_credentials')->count())->toBe(1);
    expect((new TelematicPositionWebhookController())->handle(Request::create($url, 'POST', [], [], [], [], '{}'), new Inbox(), 'afaqy')->getStatusCode())->toBe(403);
    expect((new TelematicPositionWebhookController())->handle(Request::create($rotated, 'POST', [], [], [], [], json_encode(afaqyDbUnit())), new Inbox(), 'afaqy')->getStatusCode())->toBe(200);
    config(['app.env' => 'local']);
    expect(fn () => $controller->telemetryWebhook(new Request(), $connection->uuid))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'HTTPS');
    config(['app.env' => 'production']);
});

test('polling deadline records an incomplete sweep and releases the connection lease', function () {
    $connection                      = afaqyDbFixture();
    $provider                        = new AfaqyPollingFixtureProvider();
    $job                             = new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid);
    $GLOBALS['telemetry_test_clock'] = [1000.0, 1061.0];
    try {
        expect(fn () => $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox()))->toThrow(RuntimeException::class, 'Polling time budget exceeded');
        expect(DB::table('telematic_sync_runs')->value('status'))->toBe('incomplete');
        $provider->responses = [['devices' => [], 'has_more' => false, 'next_cursor' => null]];
        $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox());
        expect(DB::table('telematic_sync_runs')->where('status', 'completed')->count())->toBe(1);
    } finally {
        unset($GLOBALS['telemetry_test_clock']);
    }
});

test('poll requests allow slow fleet responses while respecting the remaining sweep budget', function () {
    $connection = afaqyDbFixture();
    $provider   = new class extends AfaqyPollingFixtureProvider {
        public array $requests = [];

        public function fetchDevices(array $options = []): array
        {
            $this->requests[] = $options;

            return ['devices' => [], 'has_more' => count($this->requests) === 1, 'next_cursor' => count($this->requests) === 1 ? 1000 : null];
        }
    };
    $GLOBALS['telemetry_test_clock'] = [1000.0, 1000.0, 1045.0, 1045.0, 1045.0, 1046.0, 1046.0];
    try {
        (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox());
        expect($provider->requests[0]['timeout'])->toBe(45);
        expect($provider->requests[1]['timeout'])->toBe(15);
        expect(DB::table('telematic_sync_runs')->value('status'))->toBe('completed');
    } finally {
        unset($GLOBALS['telemetry_test_clock']);
    }
});

test('polling does not start a request with an unbounded zero-second timeout', function () {
    $connection                      = afaqyDbFixture();
    $provider                        = new AfaqyPollingFixtureProvider();
    $GLOBALS['telemetry_test_clock'] = [1000.0, 1059.5];
    try {
        expect(fn () => (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox()))
            ->toThrow(RuntimeException::class, 'Polling time budget exceeded');
        expect(DB::table('telematic_sync_runs')->value('status'))->toBe('incomplete');
    } finally {
        unset($GLOBALS['telemetry_test_clock']);
    }
});

test('inbox recovery quarantines unsupported adapters and completes drained sweeps', function () {
    $connection = afaqyDbFixture();
    afaqyQueueFixture();
    $id = (new Inbox())->accept($connection, afaqyDbUnit(), 'poll');
    DB::table('telematics')->update(['provider' => 'geotab']);
    $connection->provider = 'geotab';
    expect(fn () => Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration::provider($connection))->toThrow(InvalidArgumentException::class, 'does not support durable');
    $registry = app(TelematicProviderRegistry::class);
    (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
    DB::table('telematic_sync_runs')->insert(['uuid' => 'drained-run', 'telematic_uuid' => $connection->uuid, 'status' => 'ingesting', 'created_at' => now(), 'updated_at' => now()]);
    expect((new Fleetbase\FleetOps\Console\Commands\DrainTelematicInbox())->handle())->toBe(0);
    expect(DB::table('telematic_deliveries')->where('uuid', $id)->value('status'))->toBe('quarantined');
    expect(DB::table('telematic_deliveries')->where('uuid', $id)->value('error'))->toContain('Provider configuration unavailable');
    expect(DB::table('telematic_sync_runs')->value('status'))->toBe('completed');

    // A descriptor cannot opt a legacy adapter into the durable queue contract.
    $registry->register(new Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor([
        'key'                => 'legacy-durable', 'label' => 'Legacy adapter',
        'driver_class'       => Fleetbase\FleetOps\Support\Telematics\Providers\GeotabProvider::class,
        'supports_discovery' => true, 'metadata' => ['telemetry' => ['durable_ingestion' => true]],
    ]));
    $command                      = new AfaqySyncCommandProbe();
    $command->options['provider'] = ['legacy-durable'];
    expect($command->handle($registry))->toBe(0);
});

test('AFAQY sync recovers an unreadable cached token using persisted credentials', function (string $failure, string $mode) {
    $connection              = afaqyDbFixture();
    $credentials             = ['username' => 'test-account', 'password' => 'test-password'];
    $connection->credentials = Crypt::encryptString(json_encode($credentials));
    $connection->save();
    $connection->refresh();
    $persisted = $connection->getRawOriginal('credentials');
    $key       = 'afaqy:token:' . hash('sha256', 'https://api.afaqy.sa|test-account') . ':' . hash('sha256', 'test-password');
    $stored    = (new Illuminate\Encryption\Encrypter(str_repeat('o', 32), 'aes-256-cbc'))->encryptString('old-cached-token');
    if ($failure === 'tampered-mac') {
        $payload        = json_decode(base64_decode(Crypt::encryptString('old-cached-token')), true);
        $payload['mac'] = str_repeat('0', 64);
        $stored         = base64_encode(json_encode($payload));
    }
    expect(fn () => Crypt::decryptString($stored))->toThrow(Illuminate\Contracts\Encryption\DecryptException::class, 'The MAC is invalid.');
    Cache::put($key, $stored, 3600);
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        '*/auth/login'   => Illuminate\Support\Facades\Http::response(['data' => ['token' => 'replacement-token']]),
        '*/units/lists*' => Illuminate\Support\Facades\Http::response(['data' => [afaqyDbUnit()], 'pagination' => ['allCount' => 1, 'limit' => 1000, 'offset' => 0, 'resultCount' => 1]]),
    ]);
    $registry = app(TelematicProviderRegistry::class);
    $sync     = function () use ($connection, $registry, $mode) {
        $service = new TelematicService($registry);
        if ($mode === 'manual') {
            (new Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob($connection))->handle($registry, $service);
            expect($connection->refresh()->status)->toBe('active');
            expect(data_get($connection->meta, 'last_sync_result'))->toBe('success');
            expect(data_get($connection->meta, 'last_sync_linked_total'))->toBe(1);
        } else {
            (new Fleetbase\FleetOps\Jobs\PollTelematicTelemetry($connection->uuid))->handle($registry, new Inbox());
            foreach (DB::table('telematic_deliveries')->where('status', 'pending')->pluck('uuid') as $id) {
                (new ProcessTelematicDelivery($id))->handle(new Ingestor(), $service);
            }
            expect(DB::table('telematic_sync_runs')->where('status', '!=', 'completed')->count())->toBe(0);
            expect(DB::table('telematic_sync_runs')->count())->toBeGreaterThan(0);
        }
    };
    $sync();
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(1);
    expect(Crypt::decryptString(Cache::get($key)))->toBe('replacement-token');
    expect($connection->getRawOriginal('credentials'))->toBe($persisted);
    // A second run reuses the replacement token and deduplicates the same fix.
    $sync();
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(1);
    Illuminate\Support\Facades\Http::assertSentCount(3);
    Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request->url(), '/units/lists?token=replacement-token'));
})->with(['foreign-key', 'tampered-mac'])->with(['manual', 'scheduled']);
