<?php

if (!function_exists('Fleetbase\FleetOps\Support\FuelProviders\event')) {
    eval('namespace Fleetbase\FleetOps\Support\FuelProviders; function event($event = null) { \FleetOpsFuelProviderServiceEventRecorder::$events[] = $event; return $event; }');
}

use Fleetbase\FleetOps\Contracts\FuelProvider;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderDescriptor;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Fleetbase\FleetOps\Support\FuelProviders\Providers\AbstractFuelProvider;
use Fleetbase\FleetOps\Support\FuelProviders\Providers\PetroAppFuelProvider;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

function fleetOpsFuelProviderUseInMemoryConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

class FleetOpsFuelProviderServiceEventRecorder
{
    public static array $events = [];
}

class FuelProviderHarness extends AbstractFuelProvider
{
    public Collection $transactions;
    public ?Throwable $listTransactionsException = null;
    public array $listTransactionCalls           = [];

    public function __construct()
    {
        $this->transactions = collect();
    }

    public function key(): string
    {
        return 'harness';
    }

    public function name(): string
    {
        return 'Harness';
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        return $this->authenticate($connection);
    }

    public function listTransactions(FuelProviderConnection $connection, Carbon $from, Carbon $to, array $options = []): Collection
    {
        $this->listTransactionCalls[] = [$connection, $from, $to, $options];

        if ($this->listTransactionsException) {
            throw $this->listTransactionsException;
        }

        return $this->transactions;
    }

    public function exposedBaseUrl(FuelProviderConnection $connection): string
    {
        return $this->baseUrl($connection);
    }

    public function exposedHash(array $payload): string
    {
        return $this->transactionHash($payload);
    }

    public function exposedMinorCurrencyUnit($amount): ?int
    {
        return $this->minorCurrencyUnit($amount);
    }

    public function exposedDateFrom($value): ?Carbon
    {
        return $this->dateFrom($value);
    }

    public function exposedCompactIdentifier($value): ?string
    {
        return $this->compactIdentifier($value);
    }
}

class PetroAppFuelProviderHarness extends PetroAppFuelProvider
{
    public function exposedBaseUrl(FuelProviderConnection $connection): string
    {
        return $this->baseUrl($connection);
    }

    public function exposedHeaders(FuelProviderConnection $connection): array
    {
        return $this->headers($connection);
    }

    public function exposedNormalizeBill(array $bill): array
    {
        return $this->normalizeBill($bill);
    }
}

class FleetOpsFuelProviderRegistryHarness extends FuelProviderRegistry
{
    public function __construct(private ?FuelProvider $provider = null)
    {
    }

    public function all(): Collection
    {
        return collect([
            new FuelProviderDescriptor([
                'key'          => 'harness',
                'name'         => 'Harness Provider',
                'driver_class' => FuelProviderHarness::class,
            ]),
        ]);
    }

    public function resolve(string $key): FuelProvider
    {
        if ($key === 'missing') {
            return parent::resolve($key);
        }

        return $this->provider ?? new FuelProviderHarness();
    }
}

class FleetOpsFuelProviderServiceHarness extends FuelProviderService
{
    public array $vehicleResolutions                         = [];
    public array $orderResolutions                           = [];
    public array $ingestedPayloads                           = [];
    public array $matchedTransactions                        = [];
    public array $createdFuelReports                         = [];
    public array $syncRuns                                   = [];
    public ?Throwable $ingestException                       = null;
    public ?FuelProviderTransaction $ingestTransactionResult = null;

    public function exposedMatchingOrder(?FuelProviderConnection $connection = null): array
    {
        return $this->matchingOrder($connection)->all();
    }

    public function exposedNormalizeMatchingField(string $field): string
    {
        return $this->normalizeMatchingField($field);
    }

    public function exposedNormalizeIdentifier(string $identifier): string
    {
        return $this->normalizeIdentifier($identifier);
    }

    public function exposedShouldCreateFuelReport(FuelProviderConnection $connection): bool
    {
        return $this->shouldCreateFuelReport($connection);
    }

    public function exposedMatchTransaction(FuelProviderTransaction $transaction, ?FuelProviderConnection $connection = null): void
    {
        $this->matchTransaction($transaction, $connection);
    }

    public function ingestTransaction(FuelProviderConnection $connection, array $payload): FuelProviderTransaction
    {
        $this->ingestedPayloads[] = [$connection, $payload];

        if ($this->ingestException) {
            throw $this->ingestException;
        }

        if ($this->ingestTransactionResult) {
            return $this->ingestTransactionResult;
        }

        return new FleetOpsFuelProviderTransactionHarness(array_merge([
            'company_uuid'            => $connection->company_uuid,
            'provider'                => $connection->provider,
            'provider_transaction_id' => $payload['provider_transaction_id'] ?? 'txn-harness',
            'volume'                  => $payload['volume'] ?? 0,
            'amount'                  => $payload['amount'] ?? 0,
            'sync_status'             => $payload['sync_status'] ?? 'unmatched',
            'fuel_report_uuid'        => $payload['fuel_report_uuid'] ?? null,
        ], $payload));
    }

    public function createSyncRun(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, string $status = 'queued'): FuelProviderSyncRun
    {
        $syncRun = new FleetOpsFuelProviderSyncRunHarness();
        $syncRun->setRawAttributes([
            'company_uuid'                  => $connection->company_uuid,
            'fuel_provider_connection_uuid' => $connection->uuid,
            'provider'                      => $connection->provider,
            'status'                        => $status,
            'from'                          => $from,
            'to'                            => $to,
        ], true);

        $this->syncRuns[] = $syncRun;

        return $syncRun;
    }

    protected function resolveVehicle(FuelProviderTransaction $transaction, string $field): ?Vehicle
    {
        $this->vehicleResolutions[] = [$field, $transaction->vehicle_uuid];

        if ($field === 'plate_number' && $transaction->plate_number === 'ABC-123') {
            $vehicle       = new Vehicle();
            $vehicle->uuid = 'vehicle-uuid';

            return $vehicle;
        }

        return null;
    }

    protected function resolveOrder(FuelProviderTransaction $transaction): ?Order
    {
        $this->orderResolutions[] = $transaction->trip_number;

        if ($transaction->trip_number === 'ORDER-1') {
            $order       = new Order();
            $order->uuid = 'order-uuid';

            return $order;
        }

        return null;
    }

    protected function ensureFuelReport(FuelProviderTransaction $transaction): ?FuelReport
    {
        $this->createdFuelReports[] = $transaction;

        if (!$transaction->vehicle_uuid) {
            return null;
        }

        $fuelReport       = new FuelReport();
        $fuelReport->uuid = 'fuel-report-uuid';

        $transaction->fuel_report_uuid = $fuelReport->uuid;

        return $fuelReport;
    }
}

class FleetOpsFuelProviderConnectionHarness extends FuelProviderConnection
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = [])
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

class FleetOpsFuelProviderSyncRunHarness extends FuelProviderSyncRun
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = [])
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

class FleetOpsFuelProviderTransactionHarness extends FuelProviderTransaction
{
    public int $saves        = 0;
    public array $freshLoads = [];

    public function save(array $options = [])
    {
        $this->saves++;

        return true;
    }

    public function fresh($with = [])
    {
        $this->freshLoads[] = $with;

        return $this;
    }
}

function fuelProviderConnection(array $credentials = []): FuelProviderConnection
{
    $connection              = new FuelProviderConnection();
    $connection->credentials = $credentials;

    return $connection;
}

test('abstract fuel provider exposes safe defaults and helper normalization', function () {
    $provider   = new FuelProviderHarness();
    $connection = fuelProviderConnection(['base_url' => 'https://fuel.example.test/api/']);

    expect($provider->authenticate($connection))->toBe([
        'success' => true,
        'message' => 'Authentication headers prepared.',
    ])
        ->and($provider->listVehicles($connection))->toBeInstanceOf(Collection::class)
        ->and($provider->listVehicles($connection)->all())->toBe([])
        ->and($provider->listStations($connection)->all())->toBe([])
        ->and($provider->pushTrip($connection, ['id' => 'trip-1']))->toBe([
            'success' => false,
            'message' => 'Provider does not support trip push.',
        ])
        ->and($provider->syncVehicle($connection, ['id' => 'vehicle-1']))->toBe([
            'success' => false,
            'message' => 'Provider does not support vehicle sync.',
        ])
        ->and($provider->webhookPayloadToTransaction($connection, ['event' => 'fuel']))->toBeNull()
        ->and($provider->exposedBaseUrl($connection))->toBe('https://fuel.example.test/api')
        ->and($provider->exposedHash(['b' => 2, 'a' => 1]))->toBe(hash('sha256', json_encode(['b' => 2, 'a' => 1])))
        ->and($provider->exposedMinorCurrencyUnit(null))->toBeNull()
        ->and($provider->exposedMinorCurrencyUnit(''))->toBeNull()
        ->and($provider->exposedMinorCurrencyUnit('12.345'))->toBe(1235)
        ->and($provider->exposedDateFrom(null))->toBeNull()
        ->and($provider->exposedDateFrom('2026-07-01')->toDateString())->toBe('2026-07-01')
        ->and($provider->exposedCompactIdentifier('  CARD   123  '))->toBe('CARD 123')
        ->and($provider->exposedCompactIdentifier('   '))->toBeNull();
});

test('petroapp provider resolves base urls auth headers and normalized bills', function () {
    $provider = new PetroAppFuelProviderHarness();

    $defaultConnection = fuelProviderConnection(['api_token' => 'token-1']);
    $customConnection  = fuelProviderConnection([
        'base_url'  => 'https://petroapp.example.test/root/',
        'auth_type' => 'ws_sk_header',
        'api_key'   => 'key-1',
        'version'   => 'v3.1',
    ]);

    $normalized = $provider->exposedNormalizeBill([
        'id'                  => 321,
        'bill_date'           => '2026-07-10',
        'vehicle_id'          => ' vehicle-9 ',
        'vehicle_card_id'     => ' card-9 ',
        'internal_number'     => ' internal-9 ',
        'structure_number'    => ' structure-9 ',
        'plate_snum'          => ' ABC  123 ',
        'vin'                 => ' VIN9 ',
        'serial_number'       => ' SER9 ',
        'call_sign'           => ' CALL9 ',
        'trip_number'         => ' TRIP9 ',
        'station_name'        => ' Station  1 ',
        'station_lat'         => 24.7,
        'station_lng'         => 46.7,
        'num_of_liters'       => 55.5,
        'cost'                => '120.25',
        'odometer'            => 123456,
        'payment_method'      => 'card',
        'payment_method_text' => 'Fleet card',
        'branch_name'         => 'Riyadh',
        'city'                => 'Riyadh',
        'district'            => 'North',
        'delegate_name'       => 'Operator',
    ]);

    expect($provider->key())->toBe('petroapp')
        ->and($provider->name())->toBe('PetroApp')
        ->and($provider->exposedBaseUrl($defaultConnection))->toBe('https://app-public.staging.petroapp.app/webservice')
        ->and($provider->exposedBaseUrl($customConnection))->toBe('https://petroapp.example.test/root')
        ->and($provider->exposedHeaders($defaultConnection))->toBe([
            'WS-Version'    => 'v2.0',
            'Authorization' => 'Bearer token-1',
        ])
        ->and($provider->exposedHeaders($customConnection))->toBe([
            'WS-Version' => 'v3.1',
            'WS-SK'      => 'key-1',
        ])
        ->and($normalized)->toMatchArray([
            'provider'                => 'petroapp',
            'provider_transaction_id' => '321',
            'provider_vehicle_id'     => 'vehicle-9',
            'vehicle_card_id'         => 'card-9',
            'internal_number'         => 'internal-9',
            'structure_number'        => 'structure-9',
            'plate_number'            => 'ABC 123',
            'vin'                     => 'VIN9',
            'serial_number'           => 'SER9',
            'call_sign'               => 'CALL9',
            'trip_number'             => 'TRIP9',
            'station_name'            => 'Station 1',
            'station_latitude'        => 24.7,
            'station_longitude'       => 46.7,
            'volume'                  => 55.5,
            'metric_unit'             => 'l',
            'amount'                  => 12025,
            'currency'                => 'SAR',
            'odometer'                => 123456,
        ])
        ->and($normalized['transaction_at']->toDateString())->toBe('2026-07-10')
        ->and($normalized['normalized_payload'])->toMatchArray([
            'payment_method'      => 'card',
            'payment_method_text' => 'Fleet card',
            'branch_name'         => 'Riyadh',
            'city'                => 'Riyadh',
            'district'            => 'North',
            'delegate_name'       => 'Operator',
        ]);
});

test('fuel provider service lists providers and delegates credential checks', function () {
    session(['company' => 'company-uuid']);

    $provider = new FuelProviderHarness();
    $service  = new FuelProviderService(new FleetOpsFuelProviderRegistryHarness($provider));

    expect($service->providers()->all())->toBe([
        [
            'key'                => 'harness',
            'label'              => 'harness',
            'type'               => 'native',
            'description'        => null,
            'docs_url'           => null,
            'category'           => null,
            'icon'               => 'gas-pump',
            'required_fields'    => [],
            'capabilities'       => [],
            'sync_defaults'      => [],
            'setup_instructions' => [],
            'metadata'           => [],
        ],
    ])
        ->and($service->testCredentials('harness', ['api_token' => 'token-1'], 'sandbox'))->toBe([
            'success' => true,
            'message' => 'Authentication headers prepared.',
        ]);
});

test('fuel provider service updates connection test status from provider responses', function () {
    $service              = new FuelProviderService(new FleetOpsFuelProviderRegistryHarness(new FuelProviderHarness()));
    $connection           = new FleetOpsFuelProviderConnectionHarness();
    $connection->provider = 'harness';

    expect($service->testConnection($connection))->toBe([
        'success' => true,
        'message' => 'Authentication headers prepared.',
    ])
        ->and($connection->updates[0]['status'])->toBe('connected')
        ->and($connection->updates[0]['last_error'])->toBeNull();
});

test('fuel provider service normalizes matching order settings', function () {
    $service = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness());

    $legacyConnection                = fuelProviderConnection();
    $legacyConnection->sync_settings = [
        'matching_order' => ['provider_vehicle_id', 'internal_number', 'structure_number', 'plate_number', 'vehicle_card_id', 'trip_number'],
    ];

    $customConnection                = fuelProviderConnection();
    $customConnection->sync_settings = [
        'matching_order' => ['internal_number', 'bad_field', 'plate_number', 'vehicle_card_id', 'plate_number'],
    ];

    expect($service->exposedMatchingOrder($legacyConnection))->toBe([
        'plate_number',
        'internal_id',
        'vin',
        'serial_number',
        'call_sign',
        'fuel_card_number',
        'trip_number',
    ])
        ->and($service->exposedMatchingOrder($customConnection))->toBe([
            'internal_id',
            'plate_number',
            'fuel_card_number',
        ])
        ->and($service->exposedMatchingOrder())->toBe([
            'plate_number',
            'internal_id',
            'vin',
            'serial_number',
            'call_sign',
            'fuel_card_number',
            'trip_number',
        ])
        ->and($service->exposedNormalizeMatchingField('vehicle_card_id'))->toBe('fuel_card_number')
        ->and($service->exposedNormalizeIdentifier(' ab-123 cd '))->toBe('AB123CD');
});

test('fuel provider service matches transactions in configured field order', function () {
    $service                   = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness());
    $connection                = fuelProviderConnection();
    $connection->sync_settings = ['matching_order' => ['trip_number', 'plate_number', 'vin']];

    $transaction = new FuelProviderTransaction([
        'company_uuid'  => 'company-uuid',
        'trip_number'   => 'ORDER-1',
        'plate_number'  => 'ABC-123',
        'vin'           => 'VIN-1',
        'sync_settings' => [],
    ]);

    $service->exposedMatchTransaction($transaction, $connection);

    expect($transaction->order_uuid)->toBe('order-uuid')
        ->and($transaction->vehicle_uuid)->toBe('vehicle-uuid')
        ->and($service->orderResolutions)->toBe(['ORDER-1'])
        ->and($service->vehicleResolutions)->toBe([
            ['plate_number', null],
        ]);
});

test('fuel provider service summarizes successful sync transactions', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 09:00:00'));

    $provider               = new FuelProviderHarness();
    $provider->transactions = collect([
        [
            'provider_transaction_id' => 'txn-1',
            'volume'                  => 12.5,
            'amount'                  => 1500,
            'sync_status'             => 'matched',
            'fuel_report_uuid'        => 'fuel-report-1',
        ],
        [
            'provider_transaction_id' => 'txn-2',
            'volume'                  => 3.25,
            'amount'                  => 400,
            'sync_status'             => 'unmatched',
        ],
    ]);

    $service    = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness($provider));
    $connection = new FleetOpsFuelProviderConnectionHarness();
    $connection->setRawAttributes([
        'uuid'          => 'connection-uuid',
        'company_uuid'  => 'company-uuid',
        'provider'      => 'harness',
        'sync_settings' => [
            'window_days' => 3,
        ],
    ], true);

    $summary = $service->syncTransactions($connection, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-02'), ['page_size' => 50]);

    expect($summary)->toBe([
        'imported'             => 2,
        'matched'              => 1,
        'unmatched'            => 1,
        'fuel_reports_created' => 1,
        'liters'               => 15.75,
        'amount'               => 1900,
    ])
        ->and($provider->listTransactionCalls)->toHaveCount(1)
        ->and($provider->listTransactionCalls[0][3])->toBe(['page_size' => 50])
        ->and($service->ingestedPayloads)->toHaveCount(2)
        ->and($connection->updates[0]['status'])->toBe('active')
        ->and($connection->updates[0]['last_error'])->toBeNull()
        ->and($connection->updates[0]['last_sync_state']['from'])->toBe('2026-07-01T00:00:00+00:00')
        ->and($connection->updates[0]['last_sync_state']['to'])->toBe('2026-07-02T00:00:00+00:00')
        ->and($connection->updates[0]['last_sync_state']['summary'])->toBe($summary)
        ->and($service->syncRuns[0]->updates[0])->toMatchArray([
            'status'     => 'running',
            'error'      => null,
        ])
        ->and($service->syncRuns[0]->updates[1])->toMatchArray([
            'status'               => 'completed',
            'imported'             => 2,
            'matched'              => 1,
            'unmatched'            => 1,
            'fuel_reports_created' => 1,
            'liters'               => 15.75,
            'amount'               => 1900,
            'error'                => null,
        ]);

    Carbon::setTestNow();
});

test('fuel provider service records sync errors before rethrowing', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 09:30:00'));

    $provider                            = new FuelProviderHarness();
    $provider->listTransactionsException = new RuntimeException('provider offline');

    $service    = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness($provider));
    $connection = new FleetOpsFuelProviderConnectionHarness();
    $connection->setRawAttributes([
        'uuid'          => 'connection-uuid',
        'company_uuid'  => 'company-uuid',
        'provider'      => 'harness',
        'sync_settings' => [],
    ], true);

    expect(fn () => $service->syncTransactions($connection, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-02')))
        ->toThrow(RuntimeException::class, 'provider offline');

    expect($service->syncRuns[0]->updates[1])->toMatchArray([
        'status'  => 'error',
        'error'   => 'provider offline',
        'summary' => [
            'imported'             => 0,
            'matched'              => 0,
            'unmatched'            => 0,
            'fuel_reports_created' => 0,
            'liters'               => 0,
            'amount'               => 0,
        ],
    ])
        ->and($connection->updates[0])->toMatchArray([
            'status'     => 'error',
            'last_error' => 'provider offline',
        ]);

    Carbon::setTestNow();
});

test('fuel provider service manually matches vehicle and order objects', function () {
    fleetOpsFuelProviderUseInMemoryConnection();
    FleetOpsFuelProviderServiceEventRecorder::$events = [];
    Carbon::setTestNow(Carbon::parse('2026-07-26 10:00:00'));

    $service = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness());

    $vehicle       = new Vehicle();
    $vehicle->uuid = 'vehicle-uuid';

    $order       = new Order();
    $order->uuid = 'order-uuid';

    $transaction = new FleetOpsFuelProviderTransactionHarness([
        'company_uuid' => 'company-uuid',
        'provider'     => 'harness',
    ]);

    expect($service->matchVehicle($transaction, $vehicle))->toBe($transaction)
        ->and($transaction->vehicle_uuid)->toBe('vehicle-uuid')
        ->and($transaction->sync_status)->toBe('matched')
        ->and($transaction->matched_at)->not->toBeNull()
        ->and($transaction->fuel_report_uuid)->toBe('fuel-report-uuid')
        ->and($transaction->saves)->toBe(1)
        ->and($transaction->freshLoads)->toBe([['vehicle', 'driver', 'fuelReport']])
        ->and(FleetOpsFuelProviderServiceEventRecorder::$events)->toHaveCount(1);

    $service->matchOrder($transaction, $order);

    expect($transaction->order_uuid)->toBe('order-uuid')
        ->and($transaction->saves)->toBe(2)
        ->and($transaction->freshLoads)->toBe([
            ['vehicle', 'driver', 'fuelReport'],
            ['vehicle', 'driver', 'fuelReport'],
        ]);

    Carbon::setTestNow();
});

test('fuel provider service reprocesses matched and unmatched transactions', function () {
    fleetOpsFuelProviderUseInMemoryConnection();
    FleetOpsFuelProviderServiceEventRecorder::$events = [];
    Carbon::setTestNow(Carbon::parse('2026-07-26 11:00:00'));

    $matchedService = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness());
    $matched        = new FleetOpsFuelProviderTransactionHarness([
        'company_uuid'  => 'company-uuid',
        'provider'      => 'harness',
        'plate_number'  => 'ABC-123',
        'sync_status'   => 'unmatched',
    ]);
    $matched->setRelation('connection', fuelProviderConnection());

    expect($matchedService->reprocessTransaction($matched))->toBe($matched)
        ->and($matched->vehicle_uuid)->toBe('vehicle-uuid')
        ->and($matched->sync_status)->toBe('matched')
        ->and($matched->fuel_report_uuid)->toBe('fuel-report-uuid')
        ->and($matched->saves)->toBe(2)
        ->and($matched->freshLoads)->toBe([['vehicle', 'driver', 'fuelReport']]);

    $unmatchedService = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness());
    $unmatched        = new FleetOpsFuelProviderTransactionHarness([
        'company_uuid' => 'company-uuid',
        'provider'     => 'harness',
        'sync_status'  => 'matched',
    ]);
    $unmatched->setRelation('connection', fuelProviderConnection());

    $unmatchedService->reprocessTransaction($unmatched);

    expect($unmatched->vehicle_uuid)->toBeNull()
        ->and($unmatched->sync_status)->toBe('unmatched')
        ->and($unmatched->fuel_report_uuid)->toBeNull()
        ->and($unmatched->saves)->toBe(2)
        ->and(FleetOpsFuelProviderServiceEventRecorder::$events)->toHaveCount(2);

    Carbon::setTestNow();
});

test('fuel provider service reviews transactions with explicit statuses', function () {
    $service     = new FuelProviderService(new FleetOpsFuelProviderRegistryHarness());
    $transaction = new FleetOpsFuelProviderTransactionHarness([
        'meta' => ['source' => 'provider'],
    ]);

    expect(fn () => $service->reviewTransaction($transaction, 'pending'))
        ->toThrow(InvalidArgumentException::class, 'Fuel transaction review status must be reviewed or ignored.');

    $reviewed = $service->reviewTransaction($transaction, 'reviewed');

    expect($reviewed)->toBe($transaction)
        ->and($transaction->sync_status)->toBe('reviewed')
        ->and($transaction->meta['source'])->toBe('provider')
        ->and($transaction->meta['review_status'])->toBe('reviewed')
        ->and($transaction->meta)->toHaveKey('reviewed_at')
        ->and($transaction->saves)->toBe(1);
});

test('fuel provider service honors auto create fuel report settings', function () {
    $service = new FleetOpsFuelProviderServiceHarness(new FleetOpsFuelProviderRegistryHarness());

    $defaultConnection       = fuelProviderConnection();
    $disabled                = fuelProviderConnection();
    $disabled->sync_settings = ['auto_create_fuel_reports' => false];

    expect($service->exposedShouldCreateFuelReport($defaultConnection))->toBeTrue()
        ->and($service->exposedShouldCreateFuelReport($disabled))->toBeFalse();
});
