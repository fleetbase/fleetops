<?php

require_once __DIR__ . '/../../Support/TelemetryTestEnvironment.php';

use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
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

function mixedFleetDeliveryFixture(): Telematic
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
    DB::table('telematics')->insert(['uuid' => 'integration-1', 'public_id' => 'telematic_1', 'company_uuid' => 'company-1', 'provider' => 'afaqy', 'status' => 'active', 'meta' => '{}']);

    return Telematic::withoutGlobalScopes()->first();
}

function mixedFleetPosition(string $id, float $lat): array
{
    return ['_id' => $id, 'name' => $id, 'last_update' => ['dtt' => '2026-09-15 11:59:00', 'dts' => '2026-09-15 11:59:01', 'lat' => $lat, 'lng' => 46.7, 'spd' => 20, 'acc' => 1]];
}

function processMixedFleetDelivery(array $payload, string $source = 'poll'): object
{
    DB::table('telematic_sync_runs')->insert(['uuid' => 'run-1', 'telematic_uuid' => 'integration-1', 'status' => 'ingesting', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('telematic_deliveries')->insert([
        'uuid'       => 'delivery-1', 'telematic_uuid' => 'integration-1', 'run_uuid' => 'run-1', 'source' => $source,
        'status'     => 'pending', 'payload_hash' => hash('sha256', json_encode($payload)),
        'payload'    => Crypt::encryptString(json_encode($payload)), 'received_at' => now(), 'available_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    (new ProcessTelematicDelivery('delivery-1'))->handle(new Ingestor(), new TelematicService(new TelematicProviderRegistry()));

    return DB::table('telematic_deliveries')->where('uuid', 'delivery-1')->first();
}

afterEach(fn () => Carbon::setTestNow());

test('polling applies valid positions on both sides of a unit without a GPS fix', function ($missingUpdate) {
    mixedFleetDeliveryFixture();
    foreach (['first-unit', 'never-connected', 'last-unit'] as $id) {
        DB::table('devices')->insert(['uuid' => 'device-' . $id, 'public_id' => 'device_' . $id, 'telematic_uuid' => 'integration-1', 'company_uuid' => 'company-1', 'device_id' => $id, 'name' => $id, 'status' => 'offline', 'meta' => '{}']);
    }
    $middle   = ['_id' => 'never-connected', 'name' => 'Inactive tracker', 'active' => false] + $missingUpdate;
    $payload  = [mixedFleetPosition('first-unit', 24.1), $middle, mixedFleetPosition('last-unit', 24.3)];
    $delivery = processMixedFleetDelivery($payload);

    expect($delivery->status)->toBe('quarantined')
        ->and((int) $delivery->applied)->toBe(2)
        ->and((int) $delivery->invalid_count)->toBe(1)
        ->and((int) $delivery->failed)->toBe(1)
        ->and((int) $delivery->attempts)->toBe(1);
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(2);
    foreach (['first-unit' => 24.1, 'last-unit' => 24.3] as $id => $latitude) {
        $device = Device::withoutGlobalScopes()->where('device_id', $id)->firstOrFail();
        expect($device->last_position->getLat())->toBe($latitude)
            ->and($device->last_online_at->toISOString())->toBe('2026-09-15T11:59:01.000000Z')
            ->and(data_get($device->meta, 'telemetry.position_at'))->toBe('2026-09-15T11:59:00.000000Z');
    }
    $inactive = Device::withoutGlobalScopes()->where('device_id', 'never-connected')->firstOrFail();
    expect($inactive->last_online_at)->toBeNull()
        ->and(data_get($inactive->meta, 'telemetry.position_at'))->toBeNull();
    $run = DB::table('telematic_sync_runs')->where('uuid', 'run-1')->first();
    expect($run->status)->toBe('partial')->and((int) $run->applied)->toBe(2)->and((int) $run->failed)->toBe(1);
    expect(json_decode(Crypt::decryptString($delivery->payload), true))->toBe($payload);
})->with(['missing last_update' => [[]], 'null last_update' => [['last_update' => null]]]);

test('unknown vehicle events remain quarantined as external deliveries', function () {
    mixedFleetDeliveryFixture();
    $event    = mixedFleetPosition('event-unit', 24.1) + ['event' => 'zone_entry'];
    $delivery = processMixedFleetDelivery([$event], 'webhook');

    expect($delivery->status)->toBe('quarantined')
        ->and((int) $delivery->applied)->toBe(0)
        ->and($delivery->error)->toContain('Unsupported or unreadable position payload');
    expect(Device::withoutGlobalScopes()->count())->toBe(0);
    expect(DeviceEvent::withoutGlobalScopes()->count())->toBe(0);
});

test('poll deliveries still reject invalid internal envelopes', function () {
    mixedFleetDeliveryFixture();
    $delivery = processMixedFleetDelivery(['data' => [mixedFleetPosition('unit', 24.1)]]);

    expect($delivery->status)->toBe('quarantined')->and((int) $delivery->applied)->toBe(0);
    expect(Device::withoutGlobalScopes()->count())->toBe(0);
});
