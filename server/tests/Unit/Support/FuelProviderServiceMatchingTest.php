<?php

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the FuelProviderService transaction ingestion and matching pipeline
 * against an in-memory SQLite fixture: sync run creation, transaction ingest
 * with matched/unmatched branches, vehicle and order matching by identifier,
 * column- and provider-id-based vehicle resolution, and fuel report creation.
 */
if (!function_exists('Fleetbase\FleetOps\Support\FuelProviders\event')) {
    eval('namespace Fleetbase\FleetOps\Support\FuelProviders; function event($event = null) { \FleetOpsFuelServiceMatchingRecorder::$events[] = $event; return $event; }');
}

class FleetOpsFuelServiceMatchingRecorder
{
    public static array $events = [];
}

class FleetOpsFuelServiceMatchingProbe extends FuelProviderService
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(FuelProviderService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsFuelServiceMatchingBoot(): SQLiteConnection
{
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    EloquentModel::clearBootedModels();
    $pdo = new PDO('sqlite::memory:');
    // SQLite lacks the MySQL spatial functions used by the fuel report
    // location cast; a passthrough keeps the WKT string intact.
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_AsBinary', fn ($value) => $value);
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

        public function transaction(callable $callback)
        {
            return $this->c->transaction($callback);
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'fuel_provider_connections'  => ['uuid', 'public_id', 'company_uuid', 'provider', 'status', 'sync_settings'],
        'fuel_provider_sync_runs'    => ['uuid', 'public_id', 'company_uuid', 'fuel_provider_connection_uuid', 'provider', 'status', 'from', 'to', 'transactions', 'liters', 'amount', 'summary', 'error'],
        'fuel_provider_transactions' => ['uuid', 'public_id', 'company_uuid', 'fuel_provider_connection_uuid', 'fuel_report_uuid', 'vehicle_uuid', 'driver_uuid', 'order_uuid', 'provider', 'provider_transaction_id', 'provider_vehicle_id', 'vehicle_card_id', 'internal_number', 'structure_number', 'plate_number', 'vin', 'serial_number', 'call_sign', 'trip_number', 'station_name', 'station_latitude', 'station_longitude', 'transaction_at', 'volume', 'metric_unit', 'amount', 'currency', 'odometer', 'sync_status', 'matched_at', 'normalized_payload', 'raw_payload', 'meta'],
        'fuel_reports'               => ['uuid', 'public_id', 'company_uuid', 'vehicle_uuid', 'driver_uuid', 'reported_by_uuid', 'report', 'odometer', 'amount', 'currency', 'volume', 'metric_unit', 'status', 'location', 'meta'],
        'vehicles'                   => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'plate_number', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'meta', 'avatar_url'],
        'orders'                     => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'tracking_number_uuid', 'status'],
        'tracking_numbers'           => ['uuid', 'public_id', 'company_uuid', 'tracking_number'],
        'drivers'                    => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
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

    // Exercise real UUID assignment without unrelated HTTP-cache observers.
    foreach ([FuelProviderTransaction::class, Fleetbase\FleetOps\Models\FuelReport::class, Fleetbase\FleetOps\Models\FuelProviderSyncRun::class] as $modelClass) {
        new $modelClass();
        $modelClass::flushEventListeners();
        $modelClass::bootHasUuid();
    }

    session(['company' => 'company-1']);
    FleetOpsFuelServiceMatchingRecorder::$events = [];

    return $connection;
}

function fleetopsFuelServiceMatchingService(): FleetOpsFuelServiceMatchingProbe
{
    return new FleetOpsFuelServiceMatchingProbe(new FuelProviderRegistry());
}

function fleetopsFuelServiceMatchingConnection(SQLiteConnection $connection, array $attributes = []): FuelProviderConnection
{
    $connection->table('fuel_provider_connections')->insert(array_merge([
        'uuid'         => 'conn-1',
        'company_uuid' => 'company-1',
        'provider'     => 'harness',
    ], $attributes));

    return FuelProviderConnection::where('uuid', 'conn-1')->first();
}

test('create sync run persists a queued run for the connection', function () {
    $connection     = fleetopsFuelServiceMatchingBoot();
    $fuelConnection = fleetopsFuelServiceMatchingConnection($connection);

    $run = fleetopsFuelServiceMatchingService()->createSyncRun($fuelConnection);

    expect($run->status)->toBe('queued')
        ->and($connection->table('fuel_provider_sync_runs')->count())->toBe(1)
        ->and($connection->table('fuel_provider_sync_runs')->value('provider'))->toBe('harness');
});

test('ingest transaction matches a vehicle by plate number and creates a fuel report', function () {
    $connection     = fleetopsFuelServiceMatchingBoot();
    $fuelConnection = fleetopsFuelServiceMatchingConnection($connection);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'plate_number' => 'SGX-1234']);

    $transaction = fleetopsFuelServiceMatchingService()->ingestTransaction($fuelConnection, [
        'provider_transaction_id' => 'txn-1',
        'plate_number'            => 'SGX-1234',
        'amount'                  => 88,
        'currency'                => 'USD',
        'volume'                  => 40,
        'metric_unit'             => 'L',
    ]);

    expect($transaction->sync_status)->toBe('matched')
        ->and($transaction->vehicle_uuid)->toBe('vehicle-1')
        ->and($connection->table('fuel_reports')->count())->toBe(1)
        ->and(count(FleetOpsFuelServiceMatchingRecorder::$events))->toBeGreaterThanOrEqual(2);
});

test('ingest transaction marks unmatched when no vehicle resolves', function () {
    $connection     = fleetopsFuelServiceMatchingBoot();
    $fuelConnection = fleetopsFuelServiceMatchingConnection($connection);

    $transaction = fleetopsFuelServiceMatchingService()->ingestTransaction($fuelConnection, [
        'provider_transaction_id' => 'txn-2',
        'plate_number'            => 'UNKNOWN-1',
    ]);

    expect($transaction->sync_status)->toBe('unmatched')
        ->and($transaction->vehicle_uuid)->toBeNull()
        ->and($connection->table('fuel_reports')->count())->toBe(0);
});

test('match vehicle and match order resolve string identifiers', function () {
    $connection     = fleetopsFuelServiceMatchingBoot();
    $fuelConnection = fleetopsFuelServiceMatchingConnection($connection);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_test', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);
    $connection->table('fuel_provider_transactions')->insert([
        'uuid'                    => 'txn-uuid-1',
        'company_uuid'            => 'company-1',
        'provider'                => 'harness',
        'provider_transaction_id' => 'txn-3',
        'sync_status'             => 'imported',
    ]);
    $transaction = FuelProviderTransaction::where('uuid', 'txn-uuid-1')->first();

    $service = fleetopsFuelServiceMatchingService();

    $matched = $service->matchVehicle($transaction, 'vehicle_test');
    expect($matched->vehicle_uuid)->toBe('vehicle-1')
        ->and($matched->sync_status)->toBe('matched')
        ->and($matched->fuel_report_uuid)->not->toBeNull()
        ->and($connection->table('fuel_reports')->count())->toBe(1);

    $service->matchVehicle($matched, 'vehicle_test');
    expect($connection->table('fuel_reports')->count())->toBe(1);

    $withOrder = $service->matchOrder($matched, 'order_test');
    expect($withOrder->order_uuid)->toBe('order-1');
});

test('resolve order matches trip numbers against ids and tracking numbers', function () {
    $connection = fleetopsFuelServiceMatchingBoot();
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'tracking_number' => 'TRACK-999', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_abc', 'internal_id' => null, 'tracking_number_uuid' => null, 'company_uuid' => 'company-1'],
        ['uuid' => 'order-2', 'public_id' => null, 'internal_id' => 'INT-77', 'tracking_number_uuid' => null, 'company_uuid' => 'company-1'],
        ['uuid' => 'order-3', 'public_id' => null, 'internal_id' => null, 'tracking_number_uuid' => 'tn-1', 'company_uuid' => 'company-1'],
    ]);

    $probe = fleetopsFuelServiceMatchingService();

    $makeTransaction = function (string $tripNumber) {
        $transaction = new FuelProviderTransaction();
        $transaction->setRawAttributes(['company_uuid' => 'company-1', 'trip_number' => $tripNumber], true);

        return $transaction;
    };

    expect($probe->callProtected('resolveOrder', $makeTransaction('order_abc'))?->uuid)->toBe('order-1')
        ->and($probe->callProtected('resolveOrder', $makeTransaction('INT-77'))?->uuid)->toBe('order-2')
        ->and($probe->callProtected('resolveOrder', $makeTransaction('TRACK-999'))?->uuid)->toBe('order-3')
        ->and($probe->callProtected('resolveOrder', $makeTransaction('missing')))->toBeNull();
});

test('vehicle resolution matches by normalized columns and provider ids', function () {
    $connection = fleetopsFuelServiceMatchingBoot();
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'plate_number' => 'SGX 12-34', 'meta' => null],
        ['uuid' => 'vehicle-2', 'company_uuid' => 'company-1', 'plate_number' => null, 'meta' => json_encode(['fuel_provider_vehicle_id' => 'prov-9'])],
    ]);

    $probe = fleetopsFuelServiceMatchingService();

    $transaction = new FuelProviderTransaction();
    $transaction->setRawAttributes(['company_uuid' => 'company-1', 'plate_number' => 'sgx1234'], true);

    // Normalized (case/space/dash-insensitive) column match
    $vehicle = $probe->callProtected('resolveVehicleByColumns', $transaction, 'plate_number', ['plate_number']);
    expect($vehicle?->uuid)->toBe('vehicle-1');

    // Field-mapped resolution through resolveVehicle
    $byField = $probe->callProtected('resolveVehicle', $transaction, 'plate_number');
    expect($byField?->uuid)->toBe('vehicle-1')
        ->and($probe->callProtected('resolveVehicle', $transaction, 'unsupported-field'))->toBeNull();

    // Provider id resolution through vehicle meta json
    $providerTransaction = new FuelProviderTransaction();
    $providerTransaction->setRawAttributes(['company_uuid' => 'company-1', 'provider' => 'harness', 'provider_vehicle_id' => 'prov-9'], true);
    $byProvider = $probe->callProtected('resolveVehicleByProviderId', $providerTransaction);
    expect($byProvider?->uuid)->toBe('vehicle-2');

    $emptyProvider = new FuelProviderTransaction();
    $emptyProvider->setRawAttributes(['company_uuid' => 'company-1'], true);
    expect($probe->callProtected('resolveVehicleByProviderId', $emptyProvider))->toBeNull();

    // The provider-id and structure-number fields are special-cased ahead of the
    // generic field map, so they route through their own resolvers
    expect($probe->callProtected('resolveVehicle', $providerTransaction, 'provider_vehicle_id')?->uuid)->toBe('vehicle-2');

    $connection->table('vehicles')->insert(['uuid' => 'vehicle-3', 'company_uuid' => 'company-1', 'serial_number' => 'ST-77']);
    $structureTransaction = new FuelProviderTransaction();
    $structureTransaction->setRawAttributes(['company_uuid' => 'company-1', 'structure_number' => 'st77'], true);
    expect($probe->callProtected('resolveVehicle', $structureTransaction, 'structure_number')?->uuid)->toBe('vehicle-3');
});

test('ensure fuel report reuses existing reports and skips vehicleless transactions', function () {
    $connection = fleetopsFuelServiceMatchingBoot();
    $connection->table('fuel_reports')->insert(['uuid' => 'report-1', 'company_uuid' => 'company-1']);

    $probe = fleetopsFuelServiceMatchingService();

    $existing = new FuelProviderTransaction();
    $existing->setRawAttributes(['company_uuid' => 'company-1', 'fuel_report_uuid' => 'report-1'], true);
    expect($probe->callProtected('ensureFuelReport', $existing)?->uuid)->toBe('report-1');

    $vehicleless = new FuelProviderTransaction();
    $vehicleless->setRawAttributes(['company_uuid' => 'company-1'], true);
    expect($probe->callProtected('ensureFuelReport', $vehicleless))->toBeNull();

    // Station coordinates on the transaction become the report's location
    // instead of the (0, 0) placeholder
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1']);
    $located = new FuelProviderTransaction();
    $located->setRawAttributes([
        'company_uuid'      => 'company-1',
        'vehicle_uuid'      => 'vehicle-1',
        'provider'          => 'harness',
        'station_latitude'  => '1.29',
        'station_longitude' => '103.85',
    ], true);

    $report = $probe->callProtected('ensureFuelReport', $located);
    expect($report)->not->toBeNull()
        ->and($connection->table('fuel_reports')->where('uuid', $report->uuid)->value('location'))->toBe('POINT(103.85 1.29)');
});

test('reimport updates a transaction without duplicating its report and preserves ignored review', function () {
    $db         = fleetopsFuelServiceMatchingBoot();
    $connection = fleetopsFuelServiceMatchingConnection($db);
    $db->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'plate_number' => 'SGX-1234']);
    $service = fleetopsFuelServiceMatchingService();
    $payload = ['provider_transaction_id' => 'repeat-1', 'plate_number' => 'SGX-1234', 'amount' => 1250, 'currency' => 'SAR', 'volume' => 5];
    $first   = $service->ingestTransaction($connection, $payload);
    $second  = $service->ingestTransaction($connection, $payload);
    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($db->table('fuel_provider_transactions')->count())->toBe(1)
        ->and($db->table('fuel_reports')->count())->toBe(1)
        ->and($second->fuel_report_uuid)->toBe($first->fuel_report_uuid);
    $service->reviewTransaction($second, 'ignored');
    expect($service->ingestTransaction($connection, $payload)->sync_status)->toBe('ignored');
});

test('sandbox and production connections cannot overwrite one another’s provider transactions', function () {
    $db           = fleetopsFuelServiceMatchingBoot();
    $first        = fleetopsFuelServiceMatchingConnection($db);
    $second       = clone $first;
    $second->uuid = 'production-connection';
    $service      = fleetopsFuelServiceMatchingService();
    $payload      = ['provider_transaction_id' => 'shared-bill-id', 'amount' => 1000, 'currency' => 'SAR'];
    $sandbox      = $service->ingestTransaction($first, $payload);
    $production   = $service->ingestTransaction($second, $payload);
    expect($sandbox->uuid)->not->toBe($production->uuid)
        ->and($db->table('fuel_provider_transactions')->count())->toBe(2)
        ->and($sandbox->fuel_provider_connection_uuid)->toBe($first->uuid)
        ->and($production->fuel_provider_connection_uuid)->toBe($second->uuid);
});

test('empty matching rules disable automatic matching', function () {
    $db                        = fleetopsFuelServiceMatchingBoot();
    $connection                = fleetopsFuelServiceMatchingConnection($db);
    $connection->sync_settings = ['matching_order' => []];
    $db->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'plate_number' => 'SGX-1234']);
    $transaction = fleetopsFuelServiceMatchingService()->ingestTransaction($connection, ['provider_transaction_id' => 'manual-only', 'plate_number' => 'SGX-1234']);
    expect($transaction->sync_status)->toBe('unmatched')->and($transaction->vehicle_uuid)->toBeNull();
});

test('sync windows retain the configured lookback after empty runs and reject reversed dates', function () {
    $db                         = fleetopsFuelServiceMatchingBoot();
    $connection                 = fleetopsFuelServiceMatchingConnection($db);
    $connection->sync_settings  = ['window_days' => 7];
    $connection->last_synced_at = now();
    $service                    = fleetopsFuelServiceMatchingService();
    [$from, $to]                = $service->syncWindow($connection);
    expect($from->toDateString())->toBe(now()->subDays(7)->toDateString())->and($to->toDateString())->toBe(now()->toDateString());
    expect(fn () => $service->syncWindow($connection, now(), now()->subDay()))->toThrow(InvalidArgumentException::class);
});

test('ambiguous vehicle identifiers require manual matching', function () {
    $db         = fleetopsFuelServiceMatchingBoot();
    $connection = fleetopsFuelServiceMatchingConnection($db, ['sync_settings' => json_encode(['matching_order' => ['plate_number']])]);
    $db->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'plate_number' => 'ABC 1234'],
        ['uuid' => 'vehicle-2', 'company_uuid' => 'company-1', 'plate_number' => 'ABC-1234'],
    ]);
    $transaction = fleetopsFuelServiceMatchingService()->ingestTransaction($connection, ['provider_transaction_id' => 'ambiguous', 'plate_number' => 'ABC 1234']);
    expect($transaction->sync_status)->toBe('unmatched')
        ->and($transaction->vehicle_uuid)->toBeNull()
        ->and($db->table('fuel_reports')->count())->toBe(0);
});

test('activity reports persisted totals and isolates the company and connection', function () {
    $db         = fleetopsFuelServiceMatchingBoot();
    $connection = fleetopsFuelServiceMatchingConnection($db);
    foreach ([['conn-1', 'company-1', 300], ['conn-2', 'company-1', 900], ['conn-3', 'company-2', 500]] as [$id, $company, $amount]) {
        $db->table('fuel_provider_transactions')->insert(['uuid' => 'txn-' . $id, 'fuel_provider_connection_uuid' => $id, 'company_uuid' => $company, 'sync_status' => 'unmatched', 'amount' => $amount, 'currency' => 'SAR', 'volume' => 4]);
    }
    $controller = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelProviderConnectionController(fleetopsFuelServiceMatchingService());
    $activity   = $controller->activity(new Illuminate\Http\Request(), 'conn-1')->getData(true);
    expect($activity['totals'])->toMatchArray(['transactions' => 1, 'unmatched' => 1, 'liters' => 4, 'fuel_reports' => 0])
        ->and($activity['totals']['spend'])->toBe([['currency' => 'SAR', 'amount' => 300]])
        ->and($activity['connection'])->not->toHaveKey('credentials');
    session(['company' => 'company-2']);
    expect(fn () => $controller->activity(new Illuminate\Http\Request(), 'conn-1'))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('terminal worker failures mark a sync failed without destroying its last successful summary', function () {
    $db = fleetopsFuelServiceMatchingBoot();
    $db->getSchemaBuilder()->table('fuel_provider_connections', function ($table) {
        $table->text('last_error')->nullable();
        $table->text('last_sync_state')->nullable();
    });
    $db->getSchemaBuilder()->table('fuel_provider_sync_runs', function ($table) { $table->timestamp('finished_at')->nullable(); });
    fleetopsFuelServiceMatchingConnection($db, ['last_sync_state' => json_encode(['summary' => ['imported' => 1]])]);
    $db->table('fuel_provider_sync_runs')->insert(['uuid' => 'run-1', 'fuel_provider_connection_uuid' => 'conn-1', 'status' => 'running']);
    $job = new Fleetbase\FleetOps\Jobs\SyncFuelProviderTransactionsJob('conn-1', syncRunUuid: 'run-1');
    $job->failed(new RuntimeException('worker stopped'));
    expect($db->table('fuel_provider_sync_runs')->value('status'))->toBe('error')
        ->and($db->table('fuel_provider_sync_runs')->value('finished_at'))->not->toBeNull()
        ->and(FuelProviderConnection::where('uuid', 'conn-1')->first()->last_sync_state)->toBe(['summary' => ['imported' => 1]]);
});
