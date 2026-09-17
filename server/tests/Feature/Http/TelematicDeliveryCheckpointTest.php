<?php

require_once __DIR__ . '/../../Support/TelemetryTestEnvironment.php';
require_once __DIR__ . '/../../Support/ExampleTelemetryProvider.php';

use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Models\Device;
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

class CheckpointTestIngestor extends Ingestor
{
    public array $calls = [];
    public array $fail = [];
    public array $invalid = [];

    public function ingest(Telematic $telematic, TelemetryProviderInterface $provider, array $raw, TelematicService $service, ?string $receivedAt = null, string $source = 'poll'): array
    {
        $this->calls[] = $raw['tracker'];
        if (in_array($raw['tracker'], $this->fail, true)) {
            throw new RuntimeException('Temporary device failure');
        }
        $device = new Device();
        $device->meta = ['telemetry' => ['source_delay_seconds' => 1]];

        return ['device' => $device, 'invalid_position' => in_array($raw['tracker'], $this->invalid, true)];
    }
}

function checkpointDeliverySetup(int $count = 12, string $source = 'poll'): array
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => 'mysql']);
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
    app()->instance('encrypter', new Illuminate\Encryption\Encrypter(str_repeat('c', 32), 'aes-256-cbc'));
    Crypt::clearResolvedInstance('encrypter');
    Cache::flush();
    Carbon::setTestNow('2026-09-17 12:00:00 UTC');
    Schema::create('telematics', function ($schema) {
        $schema->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'status', 'meta'] as $column) {
            $schema->text($column)->nullable();
        }
        $schema->timestamps();
        $schema->timestamp('deleted_at')->nullable();
    });
    Schema::create('devices', function ($schema) {
        foreach (['company_uuid', 'telematic_uuid', 'device_id'] as $column) {
            $schema->text($column)->nullable();
        }
    });
    (require __DIR__ . '/../../../migrations/2026_09_15_000001_create_telematic_telemetry_tables.php')->up();
    DB::table('telematics')->insert(['uuid' => 'integration-checkpoint', 'public_id' => 'telematic_checkpoint', 'company_uuid' => 'company-1', 'provider' => 'example', 'status' => 'active', 'meta' => '{}']);
    $registry = new class extends TelematicProviderRegistry {
        public function resolve(string $key): Fleetbase\FleetOps\Contracts\TelematicProviderInterface
        {
            return new ExampleTelemetryProvider();
        }
    };
    app()->instance(TelematicProviderRegistry::class, $registry);
    $units = array_map(fn ($i) => ['tracker' => 'unit-' . $i, 'measured' => '2026-09-17T11:59:00Z', 'point' => [46.7, 24.0]], range(1, $count));
    $payload = $source === 'webhook' ? ['signals' => $units] : $units;
    DB::table('telematic_deliveries')->insert([
        'uuid' => 'delivery-checkpoint', 'telematic_uuid' => 'integration-checkpoint', 'source' => $source, 'status' => 'pending',
        'payload_hash' => hash('sha256', json_encode($payload)), 'payload' => Crypt::encryptString(json_encode($payload)),
        'received_at' => now(), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [new ProcessTelematicDelivery('delivery-checkpoint'), new CheckpointTestIngestor(), new TelematicService($registry), $connection];
}

function checkpointDeliveryRow(): object
{
    return DB::table('telematic_deliveries')->where('uuid', 'delivery-checkpoint')->first();
}

afterEach(function () {
    Carbon::setTestNow();
    unset($GLOBALS['telemetry_test_clock']);
});

test('slow deliveries continue beyond five reservations without replaying applied units', function ($source) {
    [$job, $ingestor, $service] = checkpointDeliverySetup(7, $source);
    for ($pass = 0; $pass < 7; $pass++) {
        $GLOBALS['telemetry_test_clock'] = [1000.0, 1041.0];
        $job->handle($ingestor, $service);
        $row = checkpointDeliveryRow();
        expect((int) $row->applied)->toBe($pass + 1);
        expect($row->status)->toBe($pass === 6 ? 'processed' : 'retry');
        expect((int) $row->attempts)->toBe($pass === 6 ? 1 : 0);
    }
    expect($ingestor->calls)->toBe(array_map(fn ($i) => 'unit-' . $i, range(1, 7)));
    expect((int) checkpointDeliveryRow()->failed)->toBe(0);
})->with(['poll', 'webhook']);

test('yield preserves failed items and invalid counts while reaching the untouched tail', function () {
    [$job, $ingestor, $service] = checkpointDeliverySetup(8);
    $ingestor->fail = ['unit-1'];
    $ingestor->invalid = ['unit-2'];
    for ($pass = 0; $pass < 8; $pass++) {
        $GLOBALS['telemetry_test_clock'] = [1000.0, 1041.0];
        $job->handle($ingestor, $service);
    }
    $row = checkpointDeliveryRow();
    expect($row->status)->toBe('retry')->and((int) $row->attempts)->toBe(1)
        ->and((int) $row->applied)->toBe(6)->and((int) $row->invalid_count)->toBe(1)->and((int) $row->failed)->toBe(2);
    expect(array_column(json_decode(Crypt::decryptString($row->retry_payload), true), 'tracker'))->toBe(['unit-1']);
    expect($ingestor->calls)->toBe(array_map(fn ($i) => 'unit-' . $i, range(1, 8)));
    $ingestor->fail = [];
    Carbon::setTestNow(now()->addSeconds(16));
    $GLOBALS['telemetry_test_clock'] = [1000.0, 1001.0];
    $job->handle($ingestor, $service);
    $row = checkpointDeliveryRow();
    expect($row->status)->toBe('quarantined')->and((int) $row->applied)->toBe(7)
        ->and((int) $row->invalid_count)->toBe(1)->and((int) $row->failed)->toBe(1)->and((int) $row->attempts)->toBe(2);
    expect(end($ingestor->calls))->toBe('unit-1');
});

test('worker restart resumes the committed checkpoint and accounts only the remaining units', function () {
    [$job, $ingestor, $service, $connection] = checkpointDeliverySetup();
    $connection->setEventDispatcher(new Illuminate\Events\Dispatcher());
    $interrupted = false;
    $connection->listen(function ($query) use (&$interrupted) {
        if (!$interrupted && str_starts_with($query->sql, 'update ') && str_contains($query->sql, 'retry_payload')) {
            $interrupted = true;
            throw new RuntimeException('Simulated worker interruption after durable checkpoint');
        }
    });
    $GLOBALS['telemetry_test_clock'] = array_fill(0, 13, 1000.0);
    expect(fn () => $job->handle($ingestor, $service))->toThrow(RuntimeException::class, 'Simulated worker interruption');
    $row = checkpointDeliveryRow();
    expect($row->status)->toBe('processing')->and((int) $row->applied)->toBe(10)->and((int) $row->attempts)->toBe(1);
    expect(array_column(json_decode(Crypt::decryptString($row->retry_payload), true)['remaining'], 'tracker'))->toBe(['unit-11', 'unit-12']);
    Carbon::setTestNow(now()->addSeconds(121));
    $GLOBALS['telemetry_test_clock'] = [1000.0, 1000.0, 1000.0];
    (new ProcessTelematicDelivery('delivery-checkpoint'))->handle($ingestor, $service);
    expect(checkpointDeliveryRow()->status)->toBe('processed')->and((int) checkpointDeliveryRow()->applied)->toBe(12)
        ->and($ingestor->calls)->toBe(array_map(fn ($i) => 'unit-' . $i, range(1, 12)));
});

test('slow single-unit checkpoints limit replay before a ten-unit boundary', function () {
    [$job, $ingestor, $service, $connection] = checkpointDeliverySetup(3);
    $connection->setEventDispatcher(new Illuminate\Events\Dispatcher());
    $connection->listen(function ($query) {
        if (str_starts_with($query->sql, 'update ') && str_contains($query->sql, 'retry_payload')) {
            throw new RuntimeException('Stop after first checkpoint');
        }
    });
    $GLOBALS['telemetry_test_clock'] = [1000.0, 1003.0];
    expect(fn () => $job->handle($ingestor, $service))->toThrow(RuntimeException::class, 'first checkpoint');
    expect((int) checkpointDeliveryRow()->applied)->toBe(1)->and($ingestor->calls)->toBe(['unit-1']);
});
