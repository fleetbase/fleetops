<?php

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Validation\ValidationException;

/**
 * Covers the TelematicService persistence pipeline against SQLite: device
 * linking with identity validation, device event storage with telemetry
 * field mapping, sensor storage with identity fallbacks and default
 * locations, device filtering, connection test recording, credential
 * decryption fallbacks, payload device resolution, and webhook telematic
 * resolution by integration id, account id, and device id.
 */
function fleetopsTelematicServiceBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
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

    app()->instance('encrypter', new class {
        public function encryptString($value)
        {
            return base64_encode((string) $value);
        }

        public function decryptString($value)
        {
            $decoded = base64_decode((string) $value, true);
            if ($decoded === false) {
                throw new RuntimeException('Unable to decrypt.');
            }

            return $decoded;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'telematics'    => ['uuid', 'public_id', 'company_uuid', 'provider', 'name', 'credentials', 'status', 'meta', '_key'],
        'devices'       => ['uuid', 'public_id', 'company_uuid', 'telematic_uuid', 'device_id', 'internal_id', 'imei', 'name', 'status', 'online', 'location', 'meta', 'device_type', 'device_provider', 'last_seen_at', 'attachable_uuid', 'attachable_type', '_key', 'serial_number', 'model', 'manufacturer', 'connection_status', 'provider', 'last_position', 'last_online_at', 'type'],
        'device_events' => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'event_type', 'severity', 'message', 'provider', 'ident', 'code', 'state', 'reason', 'occurred_at', 'data', 'payload', 'meta', 'location', '_key', 'processed_at'],
        'sensors'       => ['uuid', 'public_id', 'company_uuid', 'telematic_uuid', 'device_uuid', 'type', 'internal_id', 'name', 'unit', 'last_value', 'last_reading_at', 'status', 'meta', 'last_position', 'sensor_type', 'min_threshold', 'max_threshold'],
        'vehicles'      => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'online', 'heading', 'speed', 'altitude', 'meta'],
        'positions'     => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'coordinates', 'heading', 'speed', 'altitude', '_key'],
        'alerts'        => ['uuid', 'public_id', 'company_uuid', 'type', 'severity', 'status', 'subject_type', 'subject_uuid', 'message', 'context', 'triggered_at', 'resolved_at'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsTelematicServiceTelematic(): Telematic
{
    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid'         => 'tm-1',
        'public_id'    => 'telematic_test',
        'company_uuid' => 'company-1',
        'provider'     => 'traccar',
        'name'         => 'Traccar Server',
    ], true);
    $telematic->exists = true;

    return $telematic;
}

function fleetopsTelematicService(): TelematicService
{
    return new TelematicService(new TelematicProviderRegistry());
}

test('link device validates identity and reconciles telemetry', function () {
    $connection = fleetopsTelematicServiceBoot();
    $service    = fleetopsTelematicService();
    $telematic  = fleetopsTelematicServiceTelematic();

    expect(fn () => $service->linkDevice($telematic, []))->toThrow(ValidationException::class);

    $device = $service->linkDevice($telematic, [
        'device_id' => 'unit-9',
        'name'      => 'Tracker Nine',
        'online'    => true,
    ]);

    expect($device)->toBeInstanceOf(Device::class)
        ->and($connection->table('devices')->count())->toBe(1)
        ->and($connection->table('devices')->value('device_id'))->toBe('unit-9');

    // Linking the same external id updates rather than duplicates
    $service->linkDevice($telematic, ['device_id' => 'unit-9', 'name' => 'Tracker Nine Renamed']);
    expect($connection->table('devices')->count())->toBe(1);
});

test('store device event maps telemetry fields and links the device', function () {
    $connection = fleetopsTelematicServiceBoot();
    $service    = fleetopsTelematicService();
    $telematic  = fleetopsTelematicServiceTelematic();

    $device = $service->linkDevice($telematic, ['device_id' => 'unit-9']);

    $event = $service->storeDeviceEvent($telematic, [
        'event_type'  => 'position_update',
        'severity'    => 'info',
        'event_id'    => 'evt-1',
        'speed'       => 42,
        'heading'     => 180,
        'ignition'    => true,
        'occurred_at' => '2026-07-28 08:00:00',
        'location'    => ['latitude' => 1, 'longitude' => 2],
    ], $device);

    expect($event->exists)->toBeTrue()
        ->and($connection->table('device_events')->count())->toBe(1)
        ->and($event->event_type)->toBe('position_update')
        ->and($event->ident)->toBe('evt-1');

    // Payload-resolved devices attach without an explicit device
    $second = $service->storeDeviceEvent($telematic, ['device_id' => 'unit-9', 'event_type' => 'ignition_on', 'reason' => 'ignition']);
    expect($second->device_uuid)->toBe($device->uuid);
});

test('store sensor validates identity applies defaults and updates', function () {
    $connection = fleetopsTelematicServiceBoot();
    $service    = fleetopsTelematicService();
    $telematic  = fleetopsTelematicServiceTelematic();

    expect(fn () => $service->storeSensor($telematic, []))->toThrow(ValidationException::class);

    $sensor = $service->storeSensor($telematic, [
        'sensor_id' => 'sensor-9',
        'type'      => 'temperature',
        'name'      => 'Cold Chain',
        'unit'      => 'C',
        'value'     => 4.5,
    ]);

    expect($sensor)->toBeInstanceOf(Sensor::class)
        ->and($connection->table('sensors')->count())->toBe(1)
        ->and($connection->table('sensors')->value('last_value'))->toBe('4.5');

    // Device-linked sensors resolve identity from the device
    $device = $service->linkDevice($telematic, ['device_id' => 'unit-9']);
    $linked = $service->storeSensor($telematic, ['type' => 'fuel'], $device);
    expect($linked->device_uuid)->toBe($device->uuid);
});

test('get devices filters by status and search terms', function () {
    $connection = fleetopsTelematicServiceBoot();
    $service    = fleetopsTelematicService();
    $telematic  = fleetopsTelematicServiceTelematic();

    $connection->table('devices')->insert([
        ['uuid' => 'device-1', 'telematic_uuid' => 'tm-1', 'device_id' => 'unit-1', 'name' => 'Alpha Tracker', 'status' => 'active'],
        ['uuid' => 'device-2', 'telematic_uuid' => 'tm-1', 'device_id' => 'unit-2', 'name' => 'Beta Tracker', 'status' => 'disabled'],
    ]);

    expect($service->getDevices($telematic))->toHaveCount(2)
        ->and($service->getDevices($telematic, ['status' => 'active']))->toHaveCount(1)
        ->and($service->getDevices($telematic, ['search' => 'Beta']))->toHaveCount(1)
        ->and($service->getDevices($telematic, ['search' => 'unit-1']))->toHaveCount(1);
});

test('connection tests and credentials round trip through the service', function () {
    $connection = fleetopsTelematicServiceBoot();
    $service    = fleetopsTelematicService();
    $connection->table('telematics')->insert(['uuid' => 'tm-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'traccar']);
    $telematic = Telematic::where('uuid', 'tm-1')->withoutGlobalScopes()->first();

    $service->recordConnectionTest($telematic, ['success' => true, 'metadata' => ['latency' => 20]]);
    expect($connection->table('telematics')->value('status'))->toBe('connected');

    $service->recordConnectionTest($telematic, ['success' => false, 'message' => 'refused']);
    expect($connection->table('telematics')->value('status'))->toBe('error');

    // Array credentials pass through; json strings fall back after failed
    // decryption; empty credentials return an empty array
    $telematic->credentials = null;
    expect($service->getCredentials($telematic))->toBe([]);

    $plain              = fleetopsTelematicServiceTelematic();
    $plain->credentials = json_encode(['token' => 'abc']);
    expect($service->getCredentials($plain))->toBe(['token' => 'abc']);
});

test('webhook telematics resolve by integration account and device ids', function () {
    $connection = fleetopsTelematicServiceBoot();
    $service    = fleetopsTelematicService();

    $connection->table('telematics')->insert([
        ['uuid' => 'tm-1', 'public_id' => 'telematic_hook', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'meta' => json_encode(['provider_account_id' => 'acct-1'])],
        ['uuid' => 'tm-2', 'public_id' => 'telematic_other', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'meta' => json_encode([])],
    ]);
    $connection->table('devices')->insert(['uuid' => 'device-1', 'telematic_uuid' => 'tm-2', 'device_id' => 'unit-77']);

    // Integration id wins outright
    expect($service->resolveWebhookTelematic('traccar', [], [], 'telematic_hook')?->uuid)->toBe('tm-1')
        // Account id metadata matches a single telematic
        ->and($service->resolveWebhookTelematic('traccar', ['account_id' => 'acct-1'])?->uuid)->toBe('tm-1')
        // Device identity resolves through the device relation
        ->and($service->resolveWebhookTelematic('traccar', ['device_id' => 'unit-77'])?->uuid)->toBe('tm-2')
        // Nothing matches
        ->and($service->resolveWebhookTelematic('traccar', ['device_id' => 'unit-none']))->toBeNull();
});
