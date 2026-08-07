<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Exports\TelematicExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\TelematicController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the internal TelematicController endpoints: provider listing,
 * connection and credential tests, device discovery/listing/linking, activity
 * logs, the export endpoint, the query-record eager-load hook, and the real
 * findTelematic lookup body with its not-found path.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsInternalTelematicExcelFake
{
    public array $downloads = [];

    public function download($export, string $fileName): string
    {
        $this->downloads[] = [$export, $fileName];

        return 'downloaded:' . $fileName;
    }
}

class FleetOpsInternalTelematicServiceFake extends TelematicService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function testConnection(Telematic $telematic, bool $async = false)
    {
        $this->calls[] = ['testConnection', $telematic->uuid, $async];

        return ['success' => true, 'async' => $async];
    }

    public function discoverDevices(Telematic $telematic, array $options = []): string
    {
        $this->calls[] = ['discoverDevices', $telematic->uuid, $options];

        return 'job-123';
    }

    public function getDevices(Telematic $telematic, array $filters = [])
    {
        $this->calls[] = ['getDevices', $telematic->uuid, $filters];

        return [['uuid' => 'device-1']];
    }

    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        $this->calls[] = ['linkDevice', $telematic->uuid, $deviceData];
        $device        = new Device();
        $device->setRawAttributes(['uuid' => 'device-1', 'public_id' => 'device_linked'], true);

        return $device;
    }

    public function recordConnectionTest(Telematic $telematic, array $result): void
    {
        $this->calls[] = ['recordConnectionTest', $telematic->uuid, $result];
    }
}

class FleetOpsInternalTelematicProviderStub implements TelematicProviderInterface
{
    public array $testedCredentials = [];

    public function connect(Telematic $telematic): void
    {
    }

    public function testConnection(array $credentials): array
    {
        $this->testedCredentials[] = $credentials;

        return ['success' => true];
    }

    public function fetchDevices(array $options = []): array
    {
        return [];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return [];
    }

    public function normalizeDevice(array $payload): array
    {
        return $payload;
    }

    public function normalizeEvent(array $payload): array
    {
        return $payload;
    }

    public function normalizeSensor(array $payload): array
    {
        return $payload;
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        return true;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        return [];
    }

    public function getCredentialSchema(): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function supportsDiscovery(): bool
    {
        return true;
    }

    public function getRateLimits(): array
    {
        return [];
    }
}

class FleetOpsInternalTelematicRegistryFake extends TelematicProviderRegistry
{
    public ?TelematicProviderInterface $provider = null;

    public function resolve(string $key): TelematicProviderInterface
    {
        if ($this->provider) {
            return $this->provider;
        }

        return parent::resolve($key);
    }
}

class FleetOpsInternalTelematicQueryFake
{
    public array $withs = [];

    public function with(array $relations): self
    {
        $this->withs[] = $relations;

        return $this;
    }
}

function fleetopsInternalTelematicBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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

    $schema = $connection->getSchemaBuilder();
    $schema->create('telematics', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('provider')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('activities', function ($table) {
        $table->increments('id');
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->text('properties')->nullable();
        $table->string('event')->nullable();
        $table->string('batch_uuid')->nullable();
        $table->timestamps();
    });

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsInternalTelematicController(
    ?FleetOpsInternalTelematicServiceFake $service = null,
    ?TelematicProviderRegistry $registry = null,
): TelematicController {
    return new TelematicController($service ?? new FleetOpsInternalTelematicServiceFake(), $registry ?? new TelematicProviderRegistry());
}

function fleetopsInternalTelematicSeed(SQLiteConnection $connection, array $attributes = []): void
{
    $connection->table('telematics')->insert(array_merge([
        'uuid'         => 'telematic-1',
        'public_id'    => 'telematic_test',
        'company_uuid' => 'company-1',
        'provider'     => 'stub-provider',
    ], $attributes));
}

test('providers endpoint lists registered telematic providers', function () {
    fleetopsInternalTelematicBoot();

    $response = fleetopsInternalTelematicController()->providers();

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe([]);
});

test('on query record hook eager loads the warranty relation', function () {
    $query = new FleetOpsInternalTelematicQueryFake();

    TelematicController::onQueryRecord($query, Request::create('/int/v1/telematics'));

    expect($query->withs)->toBe([['warranty']]);
});

test('test connection endpoint returns sync and async results', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection);
    $service = new FleetOpsInternalTelematicServiceFake();

    $sync = fleetopsInternalTelematicController($service)->testConnection(Request::create('/x', 'POST'), 'telematic_test');
    expect($sync->getStatusCode())->toBe(200)
        ->and($sync->getData(true)['success'])->toBeTrue();

    $async = fleetopsInternalTelematicController($service)->testConnection(Request::create('/x', 'POST', ['async' => true]), 'telematic-1');
    expect($async->getStatusCode())->toBe(202)
        ->and($service->calls[1][2])->toBeTrue();
});

test('test credentials endpoint resolves the provider and records results', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection);
    $service            = new FleetOpsInternalTelematicServiceFake();
    $registry           = new FleetOpsInternalTelematicRegistryFake();
    $registry->provider = new FleetOpsInternalTelematicProviderStub();

    $request  = Request::create('/x', 'POST', ['credentials' => ['token' => 'abc'], 'telematic_id' => 'telematic_test']);
    $response = fleetopsInternalTelematicController($service, $registry)->testCredentials($request, 'stub-provider');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['success' => true])
        ->and($registry->provider->testedCredentials)->toBe([['token' => 'abc']])
        ->and($service->calls[0][0])->toBe('recordConnectionTest');

    $async = fleetopsInternalTelematicController($service, $registry)->testCredentials(
        Request::create('/x', 'POST', ['async' => true]),
        'stub-provider'
    );
    expect($async->getStatusCode())->toBe(202);
});

test('test credentials endpoint reports unresolvable providers', function () {
    fleetopsInternalTelematicBoot();

    $response = fleetopsInternalTelematicController()->testCredentials(Request::create('/x', 'POST'), 'unknown-provider');

    expect($response->getData(true))->toBe(['error' => "Provider 'unknown-provider' not found in registry."]);
});

test('discover endpoint initiates device discovery', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection);
    $service = new FleetOpsInternalTelematicServiceFake();

    $request  = Request::create('/x', 'POST', ['limit' => 10, 'filters' => ['status' => 'online']]);
    $response = fleetopsInternalTelematicController($service)->discover($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getData(true))->toBe(['job_id' => 'job-123', 'message' => 'Device discovery initiated'])
        ->and($service->calls[0][2])->toBe(['limit' => 10, 'filters' => ['status' => 'online']]);
});

test('devices endpoint lists devices for the telematic', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection);
    $service = new FleetOpsInternalTelematicServiceFake();

    $request  = Request::create('/x', 'GET', ['status' => 'online', 'search' => 'gps']);
    $response = fleetopsInternalTelematicController($service)->devices($request, 'telematic_test');

    expect($response->getData(true))->toBe(['data' => [['uuid' => 'device-1']]])
        ->and($service->calls[0][2])->toBe(['status' => 'online', 'search' => 'gps']);
});

test('logs endpoint merges metadata and activity logs', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection, ['meta' => json_encode(['last_sync_result' => 'completed', 'last_sync_device_count' => 3])]);

    $response = fleetopsInternalTelematicController()->logs(Request::create('/x', 'GET'), 'telematic_test');

    $logs = $response->getData(true)['logs'];
    expect($logs)->not->toBeEmpty()
        ->and($logs[0]['type'])->toBe('sync_completed');
});

test('logs endpoint surfaces a failed connection test and scrubs sensitive errors', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection, ['meta' => json_encode([
        'last_test_result' => 'failed',
        'last_error'       => 'Provider rejected the API key.',
    ])]);

    $logs = fleetopsInternalTelematicController()->logs(Request::create('/x', 'GET'), 'telematic_test')->getData(true)['logs'];

    expect($logs[0]['type'])->toBe('connection_test_failed')
        ->and($logs[0]['status'])->toBe('warning')
        ->and($logs[0]['description'])->toBe('Provider rejected the API key.');

    // A driver-level error leaks schema details, so the generic fallback stands in
    $connection->table('telematics')->update(['meta' => json_encode([
        'last_test_result' => 'failed',
        'last_error'       => 'SQLSTATE[HY000]: connection refused',
    ])]);

    $scrubbed = fleetopsInternalTelematicController()->logs(Request::create('/x', 'GET'), 'telematic_test')->getData(true)['logs'];

    expect($scrubbed[0]['description'])->toBe('Connection test failed. Review the provider credentials and try again.');
});

test('link device endpoint validates input and links through the service', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection);
    $service = new FleetOpsInternalTelematicServiceFake();

    $request  = Request::create('/x', 'POST', ['external_id' => 'ext-1', 'device_name' => 'Tracker']);
    $response = fleetopsInternalTelematicController($service)->linkDevice($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true)['device']['uuid'])->toBe('device-1')
        ->and($service->calls[0][0])->toBe('linkDevice');
});

test('export endpoint streams a telematic export download', function () {
    fleetopsInternalTelematicBoot();
    $excel = new FleetOpsInternalTelematicExcelFake();
    app()->instance('excel', $excel);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    $request  = ExportRequest::create('/int/v1/telematics/export', 'POST', ['format' => 'csv', 'selections' => ['telematic-1']]);
    $response = fleetopsInternalTelematicController()->export($request);

    expect($response)->toStartWith('downloaded:telematics-')
        ->and($response)->toEndWith('.csv')
        ->and($excel->downloads[0][0])->toBeInstanceOf(TelematicExport::class);
});

test('find telematic helper resolves records and reports missing ones', function () {
    $connection = fleetopsInternalTelematicBoot();
    fleetopsInternalTelematicSeed($connection);

    $controller = new class(new FleetOpsInternalTelematicServiceFake(), new TelematicProviderRegistry()) extends TelematicController {
        public function callProtected(string $method, array $arguments = []): mixed
        {
            $reflection = new ReflectionMethod(TelematicController::class, $method);
            $reflection->setAccessible(true);

            return $reflection->invoke($this, ...$arguments);
        }
    };

    expect($controller->callProtected('findTelematic', ['telematic_test']))->toBeInstanceOf(Telematic::class)
        ->and($controller->callProtected('findTelematic', ['telematic-1']))->toBeInstanceOf(Telematic::class);

    expect(fn () => $controller->callProtected('findTelematic', ['missing']))->toThrow(ModelNotFoundException::class);
});
