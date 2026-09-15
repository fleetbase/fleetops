<?php

require_once __DIR__ . '/../../Support/AfaqyTestCrypto.php';

use Fleetbase\FleetOps\Http\Controllers\AfaqyWebhookController;
use Fleetbase\FleetOps\Jobs\ProcessAfaqyDelivery;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Inbox;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Ingestor;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!function_exists('Fleetbase\\FleetOps\\Support\\Telematics\\Afaqy\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Support\\Telematics\\Afaqy; function broadcast($event) { $GLOBALS["afaqy_broadcasts"][] = $event; }');
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
    (require __DIR__ . '/../../../migrations/2026_09_15_000001_create_afaqy_telemetry_inbox.php')->up();
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
    expect(data_get($device->meta, 'afaqy.position_at'))->toBe('2026-09-15T11:59:00.000000Z');
    expect($device->name)->toBe('Original name');
    expect(data_get($device->meta, 'preserved'))->toBe('yes');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(2);
    expect(count($GLOBALS['afaqy_broadcasts']))->toBe(1);
    expect($device->last_position->getLat())->toBe(24.0);
});

test('webhook authenticates before durable acceptance and worker quarantines unknown shapes', function () {
    $telematic = afaqyDbFixture();
    DB::table('afaqy_webhook_tokens')->insert(['telematic_uuid' => $telematic->uuid, 'token' => Crypt::encryptString('secret')]);
    $controller = new AfaqyWebhookController();
    $inbox      = new Inbox();
    $request    = fn ($token, $body) => Request::create('/?telematic=telematic_1&key=' . $token, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    expect($controller->handle($request('wrong', afaqyDbUnit()), $inbox)->getStatusCode())->toBe(403);
    expect(DB::table('afaqy_deliveries')->count())->toBe(0);
    $response = $controller->handle($request('secret', ['unexpected' => 'event']), $inbox);
    expect($response->getStatusCode())->toBe(200);
    $id = $response->getData(true)['delivery_id'];
    expect(DB::table('afaqy_deliveries')->value('payload'))->not->toContain('unexpected');
    (new ProcessAfaqyDelivery($id))->handle(new Ingestor(), new TelematicService(new TelematicProviderRegistry()));
    expect(DB::table('afaqy_deliveries')->value('status'))->toBe('quarantined');
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(0);
});

test('durable pending delivery survives broker failure and duplicate worker execution', function () {
    $telematic = afaqyDbFixture();
    $id        = (new Inbox())->accept($telematic, [afaqyDbUnit()], 'webhook');
    $service   = new TelematicService(new TelematicProviderRegistry());
    $job       = new ProcessAfaqyDelivery($id);
    $job->handle(new Ingestor(), $service);
    $job->handle(new Ingestor(), $service);
    expect(DB::table('afaqy_deliveries')->where('uuid', $id)->value('status'))->toBe('processed');
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
    $job                 = new Fleetbase\FleetOps\Jobs\PollAfaqyTelemetry($telematic->uuid);
    $registry            = new AfaqyPollingFixtureRegistry($provider);
    $job->handle($registry, new Inbox());
    $job->handle($registry, new Inbox());
    expect(DB::table('afaqy_sync_runs')->count())->toBe(1);
    expect(DB::table('afaqy_sync_runs')->value('status'))->toBe('ingesting');
    $delivery = DB::table('afaqy_deliveries')->value('uuid');
    (new ProcessAfaqyDelivery($delivery))->handle(new Ingestor(), new TelematicService(new TelematicProviderRegistry()));
    expect(DB::table('afaqy_sync_runs')->value('status'))->toBe('completed');
    expect(DB::table('afaqy_sync_runs')->value('applied'))->toBe(1);
});

test('repeated pages are explicitly incomplete and disabled connections are not polled', function () {
    $telematic           = afaqyDbFixture();
    $provider            = new AfaqyPollingFixtureProvider();
    $provider->responses = [
        ['devices' => [afaqyDbUnit()], 'has_more' => true, 'next_cursor' => 1],
        ['devices' => [afaqyDbUnit()], 'has_more' => false, 'next_cursor' => null],
    ];
    $job = new Fleetbase\FleetOps\Jobs\PollAfaqyTelemetry($telematic->uuid);
    expect(fn () => $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox()))->toThrow(RuntimeException::class, 'Repeated');
    expect(DB::table('afaqy_sync_runs')->value('status'))->toBe('incomplete');
    DB::table('telematics')->update(['status' => 'disabled']);
    $job->handle(new AfaqyPollingFixtureRegistry($provider), new Inbox());
    expect(DB::table('afaqy_sync_runs')->count())->toBe(1);
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
    DB::table('afaqy_deliveries')->where('uuid', $processed)->update(['status' => 'processed', 'updated_at' => now()->subHours(25)]);
    DB::table('afaqy_deliveries')->where('uuid', $quarantined)->update(['status' => 'quarantined', 'updated_at' => now()->subDays(2)]);
    config(['telematics.afaqy.webhooks_enabled' => false]);
    expect((new Fleetbase\FleetOps\Console\Commands\DrainAfaqyInbox())->handle())->toBe(0);
    expect(DB::table('afaqy_deliveries')->where('uuid', $processed)->exists())->toBeFalse();
    expect(DB::table('afaqy_deliveries')->where('uuid', $quarantined)->exists())->toBeTrue();
    expect(DB::table('afaqy_deliveries')->where('uuid', $pending)->value('status'))->toBe('pending');
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
