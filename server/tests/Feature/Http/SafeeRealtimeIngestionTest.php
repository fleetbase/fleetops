<?php

require_once __DIR__ . '/../../Support/TelemetryTestEnvironment.php';

use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\Providers\SafeeProvider;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Ingestor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
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

function safeeDbFixture(): Telematic
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
    DB::table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_1', 'company_uuid' => 'company-1', 'provider' => 'safee', 'status' => 'active', 'meta' => '{}']);

    DB::table('devices')->insert(['uuid' => 'device-1', 'public_id' => 'device_1', 'company_uuid' => 'company-1', 'telematic_uuid' => 'integration-1', 'device_id' => '101', 'internal_id' => 'internal-original', 'name' => 'Existing tracker', 'imei' => 'existing-imei', 'status' => 'offline', 'meta' => '{"preserved":"yes"}']);

    return Telematic::withoutGlobalScopes()->first();
}

function safeeDbSample(string $time = '2026-09-15T11:59:00Z', float $latitude = 24.0, array $identity = []): array
{
    return [
        'id' => 101,
        '_safee' => [
            'vehicle_id' => 101,
            'identity' => ['id' => 101] + $identity,
            'current_state' => ['vehicle' => ['id' => 101], 'date' => $time, 'position' => ['lat' => $latitude, 'lon' => 46.7], 'speed' => 20, 'heading' => 90],
        ],
    ];
}

afterEach(fn () => Carbon::setTestNow());

test('safee repeated and reversed polling samples preserve current device and attached vehicle positions', function () {
    $connection = safeeDbFixture();
    DB::table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_1', 'company_uuid' => 'company-1', 'name' => 'Existing vehicle']);
    DB::table('devices')->where('uuid', 'device-1')->update(['attachable_uuid' => 'vehicle-1', 'attachable_type' => Vehicle::class]);
    $provider = new SafeeProvider();
    $service = new TelematicService(new TelematicProviderRegistry());
    $ingestor = new Ingestor();
    $sample = safeeDbSample();
    $ingestor->ingest($connection, $provider, $sample, $service);
    $duplicate = $ingestor->ingest($connection, $provider, $sample, $service);
    $ingestor->ingest($connection, $provider, safeeDbSample('2026-09-15T11:50:00Z', 12), $service);
    $device = Device::withoutGlobalScopes()->firstOrFail();
    $vehicle = Vehicle::withoutGlobalScopes()->firstOrFail();
    expect($duplicate['duplicate'])->toBeTrue()
        ->and(DeviceEvent::withoutGlobalScopes()->count())->toBe(2)
        ->and($device->last_position->getLat())->toBe(24.0)
        ->and($vehicle->location->getLat())->toBe(24.0)
        ->and($device->last_online_at->toISOString())->toBe('2026-09-15T11:59:00.000000Z')
        ->and($device->name)->toBe('Existing tracker')
        ->and($device->imei)->toBe('existing-imei')
        ->and($device->attachable_uuid)->toBe('vehicle-1')
        ->and($device->attachable_type)->toBe(Vehicle::class)
        ->and($device->internal_id)->toBe('internal-original')
        ->and(data_get($device->meta, 'preserved'))->toBe('yes');
});

test('safee timestamps with offsets deduplicate the same UTC position', function () {
    $connection = safeeDbFixture();
    $service = new TelematicService(new TelematicProviderRegistry());
    $ingestor = new Ingestor();
    $provider = new SafeeProvider();
    $ingestor->ingest($connection, $provider, safeeDbSample(), $service);
    $same = $ingestor->ingest($connection, $provider, safeeDbSample('2026-09-15T14:59:00+03:00'), $service);
    expect($same['duplicate'])->toBeTrue()
        ->and(DeviceEvent::withoutGlobalScopes()->count())->toBe(1)
        ->and(Device::withoutGlobalScopes()->first()->last_online_at->toISOString())->toBe('2026-09-15T11:59:00.000000Z');
});

test('safee missing or invalid positions cannot erase a valid current fix', function ($replacement) {
    $connection = safeeDbFixture();
    $service = new TelematicService(new TelematicProviderRegistry());
    $ingestor = new Ingestor();
    $provider = new SafeeProvider();
    $ingestor->ingest($connection, $provider, safeeDbSample(), $service);
    $invalid = safeeDbSample('2026-09-15T11:59:30Z');
    $invalid['_safee']['current_state'] = $replacement;
    $result = $ingestor->ingest($connection, $provider, $invalid, $service);
    $device = Device::withoutGlobalScopes()->firstOrFail();
    expect($result['invalid_position'])->toBeTrue()
        ->and($device->last_position->getLat())->toBe(24.0)
        ->and(data_get($device->meta, 'telemetry.position_at'))->toBe('2026-09-15T11:59:00.000000Z')
        ->and(DeviceEvent::withoutGlobalScopes()->count())->toBe(1);
})->with([
    'missing state' => [null],
    'invalid coordinates' => [['date' => '2026-09-15T11:59:30Z', 'position' => ['lat' => 200, 'lon' => 46.7]]],
    'missing timestamp' => [['position' => ['lat' => 25, 'lon' => 46.7]]],
]);

test('safee matching unit IDs remain isolated by integration and company', function () {
    $first = safeeDbFixture();
    DB::table('telematics')->insert(['uuid' => 'integration-2', 'public_id' => 'telematic_2', 'company_uuid' => 'company-2', 'provider' => 'safee', 'status' => 'active', 'meta' => '{}']);
    DB::table('devices')->insert(['uuid' => 'device-2', 'public_id' => 'device_2', 'company_uuid' => 'company-2', 'telematic_uuid' => 'integration-2', 'device_id' => '101', 'name' => 'Other tracker', 'status' => 'offline', 'meta' => '{}']);
    $second = Telematic::withoutGlobalScopes()->where('uuid', 'integration-2')->firstOrFail();
    $service = new TelematicService(new TelematicProviderRegistry());
    $ingestor = new Ingestor();
    $provider = new SafeeProvider();
    $ingestor->ingest($first, $provider, safeeDbSample(), $service);
    $ingestor->ingest($second, $provider, safeeDbSample('2026-09-15T11:59:00Z', 25), $service);
    $devices = Device::withoutGlobalScopes()->orderBy('company_uuid')->get();
    expect($devices)->toHaveCount(2)
        ->and($devices[0]->company_uuid)->toBe('company-1')
        ->and($devices[0]->last_position->getLat())->toBe(24.0)
        ->and($devices[1]->company_uuid)->toBe('company-2')
        ->and($devices[1]->last_position->getLat())->toBe(25.0)
        ->and(DeviceEvent::withoutGlobalScopes()->count())->toBe(2);
});

test('telematics broadcasts use the configured broadcast queue without changing unconfigured instances', function (?string $queue) {
    $connection = safeeDbFixture();
    config(['telematics.telemetry.broadcast_queue' => $queue]);
    DB::table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_1', 'company_uuid' => 'company-1', 'name' => 'Existing vehicle', 'telematics' => '{}']);
    DB::table('devices')->where('uuid', 'device-1')->update(['attachable_uuid' => 'vehicle-1', 'attachable_type' => Vehicle::class]);
    try {
        (new Ingestor())->ingest($connection, new SafeeProvider(), safeeDbSample(), new TelematicService(new TelematicProviderRegistry()));
        $classes = array_map('get_class', $GLOBALS['afaqy_broadcasts']);
        expect($classes)->toContain(Fleetbase\FleetOps\Events\DeviceTelemetryUpdated::class)
            ->and($classes)->toContain(Fleetbase\FleetOps\Events\VehicleLocationChanged::class);
        foreach ($GLOBALS['afaqy_broadcasts'] as $event) {
            expect($event->broadcastQueue)->toBe($queue);
        }
    } finally {
        config(['telematics.telemetry.broadcast_queue' => null]);
    }
})->with(['unconfigured' => [null], 'dedicated queue' => ['telematics-broadcasts']]);
