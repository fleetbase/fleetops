<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelProviderConnectionController;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FleetOpsFuelProviderConnectionControllerProbe extends FuelProviderConnectionController
{
    public ?FuelProviderConnection $connection = null;

    protected function findConnection(string $id): FuelProviderConnection
    {
        $this->connection?->setAttribute('lookup_id', $id);

        return $this->connection;
    }
}

class FleetOpsFuelProviderConnectionServiceFake extends FuelProviderService
{
    public array $credentialTests = [];
    public array $connectionTests = [];
    public array $syncRuns        = [];
    public array $syncs           = [];
    public Collection $providerDescriptors;
    public array $credentialResult = ['success' => true, 'message' => 'Credentials accepted.'];
    public array $connectionResult = ['success' => true, 'message' => 'Connection accepted.'];
    public array $syncSummary      = ['imported' => 2, 'matched' => 1, 'unmatched' => 1];

    public function __construct()
    {
        $this->providerDescriptors = collect([
            [
                'key'             => 'petroapp',
                'name'            => 'PetroApp',
                'required_fields' => [
                    ['name' => 'api_token', 'label' => 'API token', 'required' => true],
                    ['name' => 'region', 'label' => 'Region', 'required' => false],
                ],
            ],
        ]);
    }

    public function providers(): Collection
    {
        return $this->providerDescriptors;
    }

    public function testCredentials(string $providerKey, array $credentials, string $environment = 'production'): array
    {
        $this->credentialTests[] = [$providerKey, $credentials, $environment];

        return $this->credentialResult;
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        $this->connectionTests[] = $connection;

        return $this->connectionResult;
    }

    public function createSyncRun(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, string $status = 'queued'): FuelProviderSyncRun
    {
        $syncRun = new FleetOpsFuelProviderSyncRunFake();
        $syncRun->setRawAttributes([
            'uuid'   => 'sync-run-uuid',
            'status' => $status,
            'from'   => $from,
            'to'     => $to,
        ]);

        $this->syncRuns[] = [$connection, $from, $to, $status, $syncRun];

        return $syncRun;
    }

    public function syncTransactions(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, array $options = [], ?FuelProviderSyncRun $syncRun = null): array
    {
        $this->syncs[] = [$connection, $from, $to, $options, $syncRun];

        return $this->syncSummary;
    }
}

class FleetOpsFuelProviderConnectionFake extends FuelProviderConnection
{
    public function toArray(): array
    {
        return $this->getAttributes();
    }
}

class FleetOpsFuelProviderSyncRunFake extends FuelProviderSyncRun
{
    public bool $freshForTest = false;

    public function fresh($with = [])
    {
        $this->freshForTest = true;

        return $this;
    }
}

function fleetopsFuelProviderConnectionController(FleetOpsFuelProviderConnectionServiceFake $service): FleetOpsFuelProviderConnectionControllerProbe
{
    return new FleetOpsFuelProviderConnectionControllerProbe($service);
}

test('fuel provider connection controller lists providers and validates connection input', function () {
    session(['company' => 'company-uuid']);

    $service    = new FleetOpsFuelProviderConnectionServiceFake();
    $controller = fleetopsFuelProviderConnectionController($service);

    expect($controller->providers()->getData(true))->toBe($service->providers()->all());

    $missingProvider = [];
    expect(fn () => $controller->onBeforeCreate(new Request(), $missingProvider))
        ->toThrow(ValidationException::class);

    $unknownProvider = ['provider' => 'missing', 'credentials' => ['api_token' => 'token-1']];
    expect(fn () => $controller->onBeforeCreate(new Request(), $unknownProvider))
        ->toThrow(ValidationException::class);

    $missingRequiredCredential = ['provider' => 'petroapp', 'credentials' => []];
    expect(fn () => $controller->onBeforeCreate(new Request(), $missingRequiredCredential))
        ->toThrow(ValidationException::class);

    $input = [
        'provider'      => 'petroapp',
        'credentials'   => ['api_token' => 'token-1'],
        'sync_settings' => ['window_days' => 14],
    ];

    $controller->onBeforeCreate(new Request(), $input);

    expect($input['company_uuid'])->toBe('company-uuid')
        ->and($input['environment'])->toBe('production')
        ->and($input['status'])->toBe('configured')
        ->and($input['sync_settings']['window_days'])->toBe(14)
        ->and($input['sync_settings']['auto_create_fuel_reports'])->toBeTrue()
        ->and($input['sync_settings']['matching_order'])->toBe([
            'plate_number',
            'internal_id',
            'vin',
            'serial_number',
            'call_sign',
            'fuel_card_number',
            'trip_number',
        ]);
});

test('fuel provider connection controller preserves active status while normalizing updates', function () {
    session(['company' => 'company-uuid']);

    $service    = new FleetOpsFuelProviderConnectionServiceFake();
    $controller = fleetopsFuelProviderConnectionController($service);

    $connection = new FleetOpsFuelProviderConnectionFake();
    $connection->setRawAttributes([
        'provider'    => 'petroapp',
        'status'      => 'active',
        'credentials' => ['api_token' => 'existing-token'],
    ]);

    $input = [
        'credentials' => ['api_token' => 'updated-token'],
    ];

    $controller->onBeforeUpdate(new Request(), $connection, $input);

    expect($input)->toMatchArray([
        'company_uuid' => 'company-uuid',
        'environment'  => 'production',
        'status'       => 'active',
    ]);
});

test('fuel provider connection controller tests credentials and existing connections', function () {
    $service    = new FleetOpsFuelProviderConnectionServiceFake();
    $controller = fleetopsFuelProviderConnectionController($service);

    $credentialsResponse = $controller->testCredentials(new Request([
        'credentials' => ['api_token' => 'token-1'],
        'environment' => 'sandbox',
    ]), 'petroapp');

    expect($credentialsResponse->getStatusCode())->toBe(200)
        ->and($credentialsResponse->getData(true))->toBe([
            'success' => true,
            'message' => 'Credentials accepted.',
        ])
        ->and($service->credentialTests)->toBe([
            ['petroapp', ['api_token' => 'token-1'], 'sandbox'],
        ]);

    $service->credentialResult = ['success' => false, 'message' => 'Rejected.'];
    $rejectedResponse          = $controller->testCredentials(new Request([
        'credentials' => ['api_token' => 'bad-token'],
    ]), 'petroapp');

    expect($rejectedResponse->getStatusCode())->toBe(422);

    $connection = new FleetOpsFuelProviderConnectionFake();
    $connection->setRawAttributes(['uuid' => 'connection-uuid']);
    $controller->connection = $connection;

    $connectionResponse = $controller->testConnection(new Request(), 'connection-public');

    expect($connectionResponse->getStatusCode())->toBe(200)
        ->and($connectionResponse->getData(true))->toBe([
            'success' => true,
            'message' => 'Connection accepted.',
        ])
        ->and($service->connectionTests)->toBe([$connection])
        ->and($connection->lookup_id)->toBe('connection-public');
});

test('fuel provider connection controller runs synchronous sync with parsed windows and options', function () {
    $service    = new FleetOpsFuelProviderConnectionServiceFake();
    $controller = fleetopsFuelProviderConnectionController($service);

    $connection = new FleetOpsFuelProviderConnectionFake();
    $connection->setRawAttributes([
        'uuid'         => 'connection-uuid',
        'company_uuid' => 'company-uuid',
        'provider'     => 'petroapp',
    ]);
    $controller->connection = $connection;

    $response = $controller->sync(new Request([
        'async'   => false,
        'from'    => '2026-07-01',
        'to'      => '2026-07-10',
        'options' => ['limit' => 10],
    ]), 'connection-public');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('ok')
        ->and($response->getData(true)['summary'])->toBe($service->syncSummary)
        ->and($service->syncRuns)->toHaveCount(1)
        ->and($service->syncRuns[0][3])->toBe('running')
        ->and($service->syncs)->toHaveCount(1)
        ->and($service->syncs[0][1]->toDateString())->toBe('2026-07-01')
        ->and($service->syncs[0][2]->toDateString())->toBe('2026-07-10')
        ->and($service->syncs[0][3])->toBe(['limit' => 10])
        ->and($service->syncs[0][4])->toBe($service->syncRuns[0][4])
        ->and($service->syncs[0][4]->freshForTest)->toBeTrue();
});

test('fuel provider sync queues async runs and finds real connections', function () {
    // Async syncs queue the job and return a 202 with the queued run
    $service    = new FleetOpsFuelProviderConnectionServiceFake();
    $controller = fleetopsFuelProviderConnectionController($service);

    $connection = new FleetOpsFuelProviderConnectionFake();
    $connection->setRawAttributes([
        'uuid'         => 'connection-uuid',
        'company_uuid' => 'company-uuid',
        'provider'     => 'petroapp',
    ]);
    $controller->connection = $connection;

    $response = $controller->sync(new Request([
        'async' => true,
        'from'  => '2026-07-01',
        'to'    => '2026-07-10',
    ]), 'connection-public');

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getData(true)['message'])->toBe('Fuel provider sync queued.')
        ->and($service->syncRuns[0][3])->toBe('queued')
        ->and($service->syncs)->toHaveCount(0);

    // The real connection lookup scopes by identifier and session company
    $dbConnection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver     = new Illuminate\Database\ConnectionResolver(['default' => $dbConnection, 'mysql' => $dbConnection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $dbConnection->getSchemaBuilder();
    $schema->create('fuel_provider_connections', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'name', 'credentials', 'sync_settings', 'status', 'meta', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    session(['company' => 'company-real-1']);
    $dbConnection->table('fuel_provider_connections')->insert(['uuid' => 'fpc-real-1', 'public_id' => 'fuel_provider_connection_real1', 'company_uuid' => 'company-real-1', 'provider' => 'petroapp']);

    $reflection = new ReflectionMethod(FuelProviderConnectionController::class, 'findConnection');
    $reflection->setAccessible(true);
    $found = $reflection->invoke(fleetopsFuelProviderConnectionController(new FleetOpsFuelProviderConnectionServiceFake()), 'fuel_provider_connection_real1');
    expect($found->uuid)->toBe('fpc-real-1');
});
