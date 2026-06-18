<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Support\Telematics\Providers\FlespiProvider;
use Fleetbase\FleetOps\Support\Telematics\Providers\GeotabProvider;
use Fleetbase\FleetOps\Support\Telematics\Providers\SafeeProvider;
use Fleetbase\FleetOps\Support\Telematics\Providers\SamsaraProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('device event model and migration expose lifecycle fields used by telematics workflows', function () {
    $model     = file_get_contents(__DIR__ . '/../src/Models/DeviceEvent.php');
    $migration = file_get_contents(__DIR__ . '/../migrations/2026_06_06_000001_harden_device_events_telematics_contract.php');

    expect($migration)
        ->toContain("'message'")
        ->toContain("'occurred_at'")
        ->toContain("'processed_at'")
        ->toContain("'data'");

    expect($model)
        ->toContain("'message'")
        ->toContain("'occurred_at'")
        ->toContain("'processed_at'")
        ->toContain("'data'")
        ->toContain("'occurred_at'     => 'datetime'")
        ->toContain("'processed_at'    => 'datetime'");
});

test('webhook controller resolves integrations explicitly and does not select the first provider record', function () {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/TelematicWebhookController.php');

    expect($controller)
        ->toContain('$this->service->resolveWebhookTelematic($providerKey')
        ->toContain("'Unable to resolve telematic integration'")
        ->toContain('$result[\'devices\'] ?? []')
        ->toContain('$result[\'events\'] ?? []')
        ->toContain('$result[\'sensors\'] ?? []')
        ->toContain('Ambiguous telematic integration')
        ->toContain('validateWebhookSignature($request->getContent(), $signature')
        ->not->toContain("Telematic::where('provider', \$providerKey)\n            ->when")
        ->not->toContain("->first();\n\n        if (!\$telematic)");
});

test('telematics service requires provider identity and stores idempotent event keys', function () {
    $service = file_get_contents(__DIR__ . '/../src/Support/Telematics/TelematicService.php');

    expect($service)
        ->toContain('Provider device identity is required to link a telematics device.')
        ->toContain('public function ingestDeviceSnapshot')
        ->toContain('DeviceEvent::firstOrNew([\'_key\' => $eventKey])')
        ->toContain('reconcileDeviceTelemetry')
        ->toContain('PROTECTED_DEVICE_STATUSES')
        ->toContain("'provider_status'")
        ->toContain("'telemetry_summary'")
        ->toContain('connectionStatusForDevice')
        ->toContain('applyDeviceEventTelemetry')
        ->toContain('$event->createPosition($positionData)')
        ->toContain('updateVehicleTelemetry')
        ->toContain('broadcast(new VehicleLocationChanged')
        ->toContain('storeSnapshotSensors')
        ->toContain("\$payload['sensors'] ?? \$payload['sensors_last_val']")
        ->toContain('protected function makeEventKey')
        ->toContain('$telematic->public_id ?? $telematic->uuid')
        ->toContain('resolveWebhookTelematic')
        ->toContain('whereHas(\'device\'')
        ->toContain('meta->provider_account_id');
});

test('device details render consistent telematics connection state and timestamp', function () {
    $details = file_get_contents(__DIR__ . '/../../addon/components/device/details.hbs');
    $header  = file_get_contents(__DIR__ . '/../../addon/components/device/panel-header.hbs');

    expect($details)
        ->toContain('format-date-fns @resource.last_online_at "dd MMM yyyy, HH:mm"')
        ->not->toContain('format-date @resource.last_online_at');

    expect($header)
        ->toContain('@resource.is_online')
        ->not->toContain('@resource.online "online"');
});

test('native providers normalize device payloads to canonical FleetOps keys', function () {
    $providers = [
        file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/AfaqyProvider.php'),
        file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/FlespiProvider.php'),
        file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/GeotabProvider.php'),
        file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/SafeeProvider.php'),
        file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/SamsaraProvider.php'),
    ];

    foreach ($providers as $provider) {
        expect($provider)
            ->toContain("'device_id'")
            ->toContain("'external_id'")
            ->toContain("'name'")
            ->toContain("'provider'")
            ->toContain("'model'")
            ->toContain("'online'")
            ->toContain("'last_seen_at'")
            ->toContain("'location'")
            ->toContain("'speed'")
            ->toContain("'heading'")
            ->toContain("'altitude'");
    }
});

test('flespi telemetry normalizes positional event fields', function () {
    $event = (new FlespiProvider())->normalizeEvent([
        'id'                     => 'message-1',
        'device.id'              => 'device-1',
        'timestamp'              => 1781769600,
        'position.latitude'      => 25.2048,
        'position.longitude'     => 55.2708,
        'position.speed'         => 42,
        'position.direction'     => 91,
        'position.altitude'      => 12,
        'vehicle.mileage'        => 12345,
        'engine.ignition.status' => true,
        'fuel.level'             => 67,
    ]);

    expect($event)->toMatchArray([
        'external_id' => 'message-1',
        'device_id'   => 'device-1',
        'event_type'  => 'telemetry_update',
        'online'      => true,
        'location'    => ['lat' => 25.2048, 'lng' => 55.2708],
        'speed'       => 42,
        'heading'     => 91,
        'altitude'    => 12,
        'odometer'    => 12345,
        'ignition'    => true,
        'fuel_level'  => 67,
    ]);
    expect($event['meta'])->toHaveKeys(['raw', 'provider_status']);
});

test('samsara telemetry variants normalize positional event fields', function () {
    $event = (new SamsaraProvider())->normalizeEvent([
        'id'                 => 'event-1',
        'vehicle'            => ['id' => 'vehicle-1'],
        'time'               => '2026-06-18T08:00:00Z',
        'location'           => [
            'latitude'          => 25.2048,
            'longitude'         => 55.2708,
            'speedMilesPerHour' => 30,
            'headingDegrees'    => 180,
            'altitudeMeters'    => 16,
        ],
        'odometerMeters'     => 1000,
        'fuelPercent'        => 50,
        'gateway'            => ['status' => 'connected', 'online' => true],
    ]);

    expect($event)->toMatchArray([
        'external_id' => 'event-1',
        'device_id'   => 'vehicle-1',
        'event_type'  => 'vehicle_update',
        'online'      => true,
        'location'    => ['lat' => 25.2048, 'lng' => 55.2708],
        'speed'       => 30,
        'heading'     => 180,
        'altitude'    => 16,
        'odometer'    => 1000,
        'fuel_level'  => 50,
    ]);
    expect($event['meta']['provider_status'])->toMatchArray([
        'gateway_status' => 'connected',
        'online'         => true,
    ]);
});

test('safee telemetry includes online and altitude event fields', function () {
    $event = (new SafeeProvider())->normalizeEvent([
        'id'            => 'event-1',
        'deviceId'      => 'device-1',
        'status'        => 'online',
        'date'          => '2026-06-18T08:00:00Z',
        'lat'           => 25.2048,
        'lon'           => 55.2708,
        'speed'         => 30,
        'heading'       => 100,
        'altitude'      => 15,
    ]);

    expect($event)->toMatchArray([
        'external_id' => 'event-1',
        'device_id'   => 'device-1',
        'online'      => true,
        'location'    => ['lat' => 25.2048, 'lng' => 55.2708],
        'speed'       => 30,
        'heading'     => 100,
        'altitude'    => 15,
    ]);
});

test('geotab latest log record drives device and event telemetry', function () {
    $payload = [
        'id'                          => 'device-1',
        'name'                        => 'Truck 1',
        'deviceType'                  => 'GO9',
        'serialNumber'                => 'serial-1',
        'vehicleIdentificationNumber' => 'VIN123',
        'latest_log_record'           => [
            'id'        => 'log-1',
            'dateTime'  => '2026-06-18T08:00:00Z',
            'latitude'  => 25.2048,
            'longitude' => 55.2708,
            'speed'     => 55,
            'bearing'   => 90,
            'altitude'  => 20,
            'device'    => ['id' => 'device-1'],
        ],
    ];

    $provider = new GeotabProvider();
    $device   = $provider->normalizeDevice($payload);
    $event    = $provider->normalizeEvent($payload);

    expect($device)->toMatchArray([
        'device_id'     => 'device-1',
        'external_id'   => 'device-1',
        'name'          => 'Truck 1',
        'provider'      => 'geotab',
        'model'         => 'GO9',
        'imei'          => 'serial-1',
        'vin'           => 'VIN123',
        'serial_number' => 'serial-1',
        'online'        => true,
        'location'      => ['lat' => 25.2048, 'lng' => 55.2708],
        'speed'         => 55,
        'heading'       => 90,
        'altitude'      => 20,
    ]);

    expect($event)->toMatchArray([
        'external_id' => 'log-1',
        'device_id'   => 'device-1',
        'event_type'  => 'status_data',
        'online'      => true,
        'location'    => ['lat' => 25.2048, 'lng' => 55.2708],
        'speed'       => 55,
        'heading'     => 90,
        'altitude'    => 20,
    ]);
});

test('geotab polling fetches recent log records and merges latest record into device snapshots', function () {
    $requests = [];

    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request;
        $body       = json_decode($request->body(), true);
        $typeName   = data_get($body, 'params.typeName');

        if ($typeName === 'Device') {
            return Http::response([
                'result' => [
                    ['id' => 'device-1', 'name' => 'Truck 1'],
                    ['id' => 'device-2', 'name' => 'Truck 2'],
                ],
            ], 200);
        }

        if ($typeName === 'LogRecord') {
            return Http::response([
                'result' => [
                    ['id' => 'old-log', 'device' => ['id' => 'device-1'], 'dateTime' => '2026-06-18T07:00:00Z', 'latitude' => 1, 'longitude' => 2],
                    ['id' => 'new-log', 'device' => ['id' => 'device-1'], 'dateTime' => '2026-06-18T08:00:00Z', 'latitude' => 3, 'longitude' => 4],
                    ['id' => 'other-log', 'device' => ['id' => 'other-device'], 'dateTime' => '2026-06-18T08:00:00Z', 'latitude' => 5, 'longitude' => 6],
                ],
            ], 200);
        }

        return Http::response(['result' => []], 200);
    });

    $provider = new class extends GeotabProvider {
        public function fetchDevicesForTest(): array
        {
            $this->credentials = [
                'database' => 'testing-db',
            ];
            $this->sessionId = 'testing-session';

            return $this->fetchDevices(['limit' => 2, 'from_date' => '2026-06-18T00:00:00Z']);
        }
    };

    $result = $provider->fetchDevicesForTest();

    expect($result['devices'])->toHaveCount(2);
    expect($result['devices'][0]['latest_log_record'])->toMatchArray([
        'id'        => 'new-log',
        'latitude'  => 3,
        'longitude' => 4,
    ]);
    expect($result['devices'][1])->not->toHaveKey('latest_log_record');
    expect($requests)->toHaveCount(2);
    expect($requests[0]->data())->toMatchArray([
        'method' => 'Get',
        'params' => [
            'typeName'     => 'Device',
            'resultsLimit' => 2,
        ],
    ]);
    expect($requests[1]->data())->toMatchArray([
        'method' => 'Get',
        'params' => [
            'typeName'     => 'LogRecord',
            'search'       => ['fromDate' => '2026-06-18T00:00:00Z'],
            'resultsLimit' => 100,
        ],
    ]);
});

test('telematics details use public id for consumer webhook URLs and do not read ember uuid', function () {
    $component = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.js');
    $template  = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.hbs');

    expect($component)
        ->toContain('const id = this.args.resource?.public_id;')
        ->toContain('return null;')
        ->not->toContain('this.args.resource?.uuid')
        ->not->toContain('this.args.resource?.public_id ?? this.args.resource?.id');

    expect($template)
        ->toContain('this.hasWebhookUrl')
        ->toContain('Webhook URL unavailable until this integration has a public ID.')
        ->toContain('last_sync_job_id')
        ->toContain('last_sync_error');
});

test('native telematics providers expose local provider icons with a descriptor fallback', function () {
    $config    = include __DIR__ . '/../config/telematics.php';
    $iconPath  = '/engines-dist/images/telematics/providers/';
    $providers = array_filter($config['providers'], fn ($provider) => ($provider['type'] ?? 'native') === 'native');

    foreach ($providers as $provider) {
        $icon = $provider['icon'] ?? null;

        expect($icon)
            ->not->toBeNull()
            ->not->toContain('http://')
            ->not->toContain('https://');

        expect(str_starts_with($icon, $iconPath))->toBeTrue();
        expect(str_ends_with($icon, '.webp'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../../assets/images/telematics/providers/' . basename($icon)))->toBeTrue();
    }

    $safee = collect($providers)->firstWhere('key', 'safee');

    expect($safee['icon'])->toBe($iconPath . 'safee.webp');

    $descriptor = new TelematicProviderDescriptor([
        'key'   => 'custom',
        'label' => 'Custom',
    ]);

    expect($descriptor->icon)->toBe(TelematicProviderDescriptor::DEFAULT_ICON);
    expect(file_exists(__DIR__ . '/../../assets/images/telematics/providers/default.webp'))->toBeTrue();
});

test('device event mark processed action is routed and company scoped', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/DeviceEventController.php');

    expect($routes)
        ->toContain("\$router->fleetbaseRoutes('device-events', function")
        ->toContain("'{id}/mark-processed'")
        ->toContain("\$controller('markProcessed')");

    expect($controller)
        ->toContain('public function markProcessed(string $id): JsonResponse')
        ->toContain("where('company_uuid', session('company'))")
        ->toContain("where('uuid', \$id)->orWhere('public_id', \$id)")
        ->toContain('$deviceEvent->markAsProcessed()')
        ->toContain("'Event was already processed.'");
});

test('afaqy sync stores compact device diagnostics and paginates complete units lists', function () {
    $provider = file_get_contents(__DIR__ . '/../src/Support/Telematics/Providers/AfaqyProvider.php');

    expect($provider)
        ->toContain('TelematicProviderException')
        ->toContain('compactLastUpdate')
        ->toContain("'provider_unit_id'")
        ->toContain("'plate_number'")
        ->toContain("'capabilities'")
        ->not->toContain("'raw'          => \$payload")
        ->not->toContain("'sensors',")
        ->toContain("'Authorization' => 'Bearer ' . \$token")
        ->toContain('protected function authenticatedPost')
        ->toContain('protected function refreshToken')
        ->toContain('protected function isTokenRejected')
        ->toContain('protected int $dataTimeout       = 120')
        ->toContain('protected int $connectTimeout    = 15')
        ->toContain('protected int $connectionTestTimeout = 30')
        ->toContain('protected int $connectionTestConnectTimeout = 10')
        ->toContain('->timeout($timeout)')
        ->toContain('->connectTimeout($connectTimeout)')
        ->toContain('ConnectionException')
        ->toContain('transportErrorContext')
        ->toContain('extractBytesReceived')
        ->toContain("'requested_limit'")
        ->toContain("'requested_offset'")
        ->toContain("'bytes_received'")
        ->toContain('AFAQY token rejected; refreshing token and retrying request')
        ->toContain('AFAQY token rejected after refresh with status')
        ->toContain('AFAQY token rejected and username/password credentials are required to refresh it.')
        ->toContain('providerErrorContext')
        ->toContain("'endpoint'")
        ->toContain("'provider_code'")
        ->toContain("'provider_message'")
        ->toContain("'retry_attempted'")
        ->toContain('?? 500), 500')
        ->toContain('if (is_array($filters) && empty($filters))')
        ->toContain('$filters = new \stdClass();')
        ->toContain("'limit'      => \$limit")
        ->toContain("'offset'     => \$offset")
        ->not->toContain("\n            'limit'  => \$limit,\n            'offset' => \$offset,")
        ->toContain("\$this->afaqyPost('/units/lists', [")
        ->toContain('], true);')
        ->toContain("http_build_query(['token' => \$this->credentials['token']])")
        ->toContain("\$body = \$tokenInQuery ? \$payload : array_merge(['token' => \$this->credentials['token']], \$payload)")
        ->toContain('$pageOffset  = (int) ($pagination[\'offset\'] ?? $offset);')
        ->toContain('$pageLimit   = (int) ($pagination[\'limit\'] ?? $limit);')
        ->toContain('$advanceBy   = $resultCount > 0 ? $resultCount : max($pageLimit, count($devices), 1);')
        ->toContain('$nextCursor  = ($pageOffset + $advanceBy) < $total ? $pageOffset + $advanceBy : null;')
        ->toContain("'requested_limit'")
        ->toContain("'provider_limit'")
        ->toContain("'provider_result_count'")
        ->toContain("'pagination'  => [")
        ->toContain("'allCount'")
        ->toContain("'filtersCount'")
        ->toContain("'resultCount'")
        ->toContain("'online'      => \$payload['active'] ?? null")
        ->toContain("'altitude'   => \$lastUpdate['alt'] ?? null");
});

test('afaqy sync keeps default limit and uses extended data request path', function () {
    $requests = [];

    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request;

        if (str_ends_with($request->url(), '/auth/login')) {
            return Http::response(['data' => ['token' => 'testing-token']], 200);
        }

        return Http::response([
            'data'       => [],
            'pagination' => ['resultCount' => 0],
        ], 200);
    });

    $provider = new class extends AfaqyProvider {
        public function fetchDevicesForTest(array $credentials): array
        {
            $this->credentials = $credentials;
            $this->prepareAuthentication();

            return $this->fetchDevices();
        }
    };

    $result = $provider->fetchDevicesForTest([
        'base_url' => 'https://api.afaqy.test',
        'username' => 'testing-user',
        'password' => 'testing-password',
    ]);

    expect($result['pagination']['resultCount'])->toBe(0);
    expect($requests)->toHaveCount(2);
    expect($requests[0]->url())->toBe('https://api.afaqy.test/auth/login');
    expect($requests[1]->url())->toContain('/units/lists?token=testing-token');
    expect($requests[1]->body())->toContain('"limit":500');
});

test('afaqy sync timeout failures are converted to sanitized provider metadata', function () {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/auth/login')) {
            return Http::response(['data' => ['token' => 'timeout-testing-token']], 200);
        }

        throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds with 1186621 bytes received for https://api.afaqy.test/units/lists?token=timeout-testing-token');
    });

    $provider = new class extends AfaqyProvider {
        public function fetchDevicesForTest(array $credentials): array
        {
            $this->credentials = $credentials;
            $this->prepareAuthentication();

            return $this->fetchDevices();
        }
    };

    try {
        $provider->fetchDevicesForTest([
            'base_url' => 'https://api.afaqy.test',
            'username' => 'testing-user',
            'password' => 'testing-password',
        ]);
    } catch (Throwable $e) {
        $result = [
            'success'  => false,
            'message'  => $e->getMessage(),
            'metadata' => method_exists($e, 'context') ? $e->context() : [],
        ];
    }

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('AFAQY API request timed out while waiting for provider response.');
    expect($result['metadata'])->toMatchArray([
        'provider'         => 'afaqy',
        'endpoint'         => '/units/lists',
        'requested_limit'  => 500,
        'requested_offset' => 0,
        'timeout'          => 120,
        'connect_timeout'  => 15,
        'bytes_received'   => 1186621,
        'retry_attempted'  => false,
        'transport_error'  => 'connection_exception',
    ]);
    expect(json_encode($result))
        ->not->toContain('timeout-testing-token')
        ->not->toContain('token=')
        ->not->toContain('testing-password')
        ->not->toContain('testing-user');
});

test('afaqy credential test refreshes token once when units list rejects token', function () {
    $authCount = 0;
    $requests  = [];

    Http::fake(function ($request) use (&$authCount, &$requests) {
        $requests[] = $request;

        if (str_ends_with($request->url(), '/auth/login')) {
            $authCount++;

            return Http::response(['data' => ['token' => $authCount === 1 ? 'first-testing-token' : 'second-testing-token']], 200);
        }

        if (str_contains($request->url(), '/units/lists?token=first-testing-token')) {
            return Http::response(['message' => 'Token expired', 'code' => 'TOKEN_EXPIRED'], 401);
        }

        return Http::response([
            'data'       => [['_id' => 'unit-1', 'name' => 'Truck 1']],
            'pagination' => ['resultCount' => 1],
        ], 200);
    });

    $result = (new AfaqyProvider())->testConnection([
        'base_url' => 'https://api.afaqy.test',
        'username' => 'testing-user',
        'password' => 'testing-password',
    ]);

    expect($result['success'])->toBeTrue();
    expect($authCount)->toBe(2);
    expect(collect($requests)->filter(fn ($request) => str_contains($request->url(), '/units/lists'))->count())->toBe(2);
    expect($requests[1]->url())->toContain('/units/lists?token=first-testing-token');
    expect($requests[2]->url())->toContain('/auth/login');
    expect($requests[3]->url())->toContain('/units/lists?token=second-testing-token');
});

test('afaqy token rejection failure metadata is sanitized', function () {
    $authCount = 0;

    Http::fake(function ($request) use (&$authCount) {
        if (str_ends_with($request->url(), '/auth/login')) {
            $authCount++;

            return Http::response(['data' => ['token' => 'rejected-testing-token-' . $authCount]], 200);
        }

        return Http::response(['message' => 'Token rejected', 'code' => 'TOKEN_REJECTED'], 401);
    });

    $result = (new AfaqyProvider())->testConnection([
        'base_url' => 'https://api.afaqy.test',
        'username' => 'testing-user',
        'password' => 'testing-password',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('AFAQY token rejected after refresh with status 401');
    expect($result['metadata'])->toMatchArray([
        'provider'         => 'afaqy',
        'endpoint'         => '/units/lists',
        'status_code'      => 401,
        'provider_code'    => 'TOKEN_REJECTED',
        'provider_message' => 'Token rejected',
        'retry_attempted'  => true,
    ]);
    expect(json_encode($result))
        ->not->toContain('rejected-testing-token')
        ->not->toContain('testing-password')
        ->not->toContain('testing-user');
});

test('afaqy supplied token rejection requires password credentials for refresh', function () {
    Http::fake([
        'https://api.afaqy.test/units/lists?token=static-testing-token' => Http::response(['message' => 'Token rejected'], 401),
    ]);

    $result = (new AfaqyProvider())->testConnection([
        'base_url' => 'https://api.afaqy.test',
        'token'    => 'static-testing-token',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('AFAQY token rejected and username/password credentials are required to refresh it.');
    expect($result['metadata'])->toMatchArray([
        'provider'         => 'afaqy',
        'endpoint'         => '/units/lists',
        'status_code'      => 401,
        'provider_message' => 'Token rejected',
        'retry_attempted'  => false,
    ]);
    expect(json_encode($result))->not->toContain('static-testing-token');
});

test('telematics device sync records provider pagination and skipped device counts', function () {
    $job        = file_get_contents(__DIR__ . '/../src/Jobs/SyncTelematicDevicesJob.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/TelematicController.php');

    expect($controller)
        ->toContain("'limit'   => \$request->input('limit')")
        ->not->toContain("'limit'   => \$request->input('limit', 100)");

    expect($job)
        ->toContain('public int $tries   = 1')
        ->toContain("'limit'   => \$this->options['limit'] ?? null")
        ->toContain('Cache::lock($lockKey, $this->timeout + 60)')
        ->toContain("'fleetops:sync-telematic-devices:' . \$this->telematic->uuid")
        ->toContain('Device discovery skipped because another sync is already running')
        ->toContain("'last_sync_skipped_reason'")
        ->toContain("'sync_already_running'")
        ->toContain('$totalFetched')
        ->toContain('$totalLinked')
        ->toContain('$totalEvents')
        ->toContain('$totalSensors')
        ->toContain('$totalSkipped')
        ->toContain('$pageCount')
        ->toContain('$service->ingestDeviceSnapshot($this->telematic, $provider, $devicePayload)')
        ->toContain('Device discovery page fetched')
        ->toContain("'provider_unit_id'")
        ->toContain("'last_sync_fetched_total'")
        ->toContain("'last_sync_linked_total'")
        ->toContain("'last_sync_events_total'")
        ->toContain("'last_sync_sensors_total'")
        ->toContain("'last_sync_skipped_total'")
        ->toContain("'last_sync_page_count'")
        ->toContain("'last_sync_provider_total'")
        ->toContain("'last_sync_provider_all_count'")
        ->toContain("'last_sync_provider_filters_count'")
        ->toContain("'last_sync_error_context'")
        ->toContain('safeSyncErrorMessage')
        ->toContain('token=|password|client_secret|authorization')
        ->toContain("method_exists(\$e, 'context') ? \$e->context() : []");
});

test('telematics polling command is registered and scheduled for discovery providers by default', function () {
    $command  = file_get_contents(__DIR__ . '/../src/Console/Commands/SyncTelematics.php');
    $provider = file_get_contents(__DIR__ . '/../src/Providers/FleetOpsServiceProvider.php');
    $details  = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.hbs');

    expect($command)
        ->toContain("protected \$signature = 'fleetops:sync-telematics")
        ->toContain('{--exclude-webhook-providers : Skip providers that support webhooks}')
        ->toContain('SyncTelematicDevicesJob::dispatch($telematic')
        ->toContain('$excludeWebhookProviders = (bool) $this->option(\'exclude-webhook-providers\')')
        ->toContain('!$excludeWebhookProviders || !$descriptor->supportsWebhooks')
        ->toContain('$descriptor->supportsDiscovery')
        ->toContain("whereIn('status', ['active', 'connected'])")
        ->not->toContain('sync-webhook-providers');

    expect($provider)
        ->toContain('Console\\Commands\\SyncTelematics::class')
        ->toContain("command('fleetops:sync-telematics')->everyMinute()");

    expect($details)
        ->toContain('Provider polling')
        ->toContain('FleetOps polls this provider for device snapshots and telemetry updates.');
});

test('native endpoint fields are advanced optional overrides with provider defaults', function () {
    $config = include __DIR__ . '/../config/telematics.php';

    $afaqy = collect($config['providers'])->firstWhere('key', 'afaqy');
    $safee = collect($config['providers'])->firstWhere('key', 'safee');

    $afaqyBaseUrl   = collect($afaqy['required_fields'])->firstWhere('name', 'base_url');
    $safeeServerUri = collect($safee['required_fields'])->firstWhere('name', 'server_uri');

    foreach ([$afaqyBaseUrl, $safeeServerUri] as $field) {
        expect($field['required'])->toBeFalse();
        expect($field['advanced'])->toBeTrue();
        expect($field['is_endpoint'])->toBeTrue();
        expect($field['validation'])->toBe('nullable|url');
        expect($field['default_value'])->not->toBeEmpty();
    }
});

test('safee credential test sends documented form auth request to custom server uri', function () {
    $requests = [];

    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request;

        if (str_ends_with($request->url(), '/protocol/openid-connect/token')) {
            return Http::response(['access_token' => 'testing-access-token'], 200);
        }

        return Http::response([
            'code'    => 0,
            'time'    => 1509946353.033,
            'status'  => 'success',
            'message' => 'operation completed successfully',
        ], 200);
    });

    $result = (new SafeeProvider())->testConnection([
        'server_uri'    => ' https://fms.example.test/ ',
        'realm_id'      => 'dsco',
        'client_id'     => 'api',
        'client_secret' => 'testing-client-secret',
        'username'      => 'testing-user',
        'password'      => 'testing-password',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['metadata'])
        ->toMatchArray([
            'auth_host' => 'https://fms.example.test',
            'auth_path' => '/auth/realms/dsco/protocol/openid-connect/token',
            'realm_id'  => 'dsco',
        ]);

    expect($requests)->toHaveCount(2);

    $tokenRequest = $requests[0];
    parse_str($tokenRequest->body(), $tokenBody);

    expect($tokenRequest->method())->toBe('POST');
    expect($tokenRequest->url())->toBe('https://fms.example.test/auth/realms/dsco/protocol/openid-connect/token');
    expect(implode(' ', (array) $tokenRequest->header('Content-Type')))->toContain('application/x-www-form-urlencoded');
    expect($tokenBody)->toMatchArray([
        'grant_type'    => 'password',
        'client_secret' => 'testing-client-secret',
        'client_id'     => 'api',
        'username'      => 'testing-user',
        'password'      => 'testing-password',
    ]);

    expect($requests[1]->method())->toBe('GET');
    expect($requests[1]->url())->toBe('https://fms.example.test/api/v2/status');
    expect(implode(' ', (array) $requests[1]->header('Authorization')))->toBe('Bearer testing-access-token');
});

test('safee credential test reports token endpoint 401 with sanitized auth context', function () {
    Http::fake([
        'https://fms.example.test/auth/realms/dsco/protocol/openid-connect/token' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $result = (new SafeeProvider())->testConnection([
        'server_uri'    => 'https://fms.example.test',
        'realm_id'      => 'dsco',
        'client_id'     => 'api',
        'client_secret' => 'testing-client-secret',
        'username'      => 'testing-user',
        'password'      => 'testing-password',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('Safee authentication failed with status 401');
    expect($result['metadata'])
        ->toMatchArray([
            'auth_host' => 'https://fms.example.test',
            'auth_path' => '/auth/realms/dsco/protocol/openid-connect/token',
            'realm_id'  => 'dsco',
        ])
        ->not->toHaveKey('client_secret')
        ->not->toHaveKey('password')
        ->not->toHaveKey('access_token')
        ->not->toHaveKey('refresh_token');
});

test('telematics activity logging excludes large json and spatial payloads', function () {
    $device      = telematics_activity_log_method(file_get_contents(__DIR__ . '/../src/Models/Device.php'));
    $sensor      = telematics_activity_log_method(file_get_contents(__DIR__ . '/../src/Models/Sensor.php'));
    $deviceEvent = telematics_activity_log_method(file_get_contents(__DIR__ . '/../src/Models/DeviceEvent.php'));

    foreach ([$device, $sensor, $deviceEvent] as $model) {
        expect($model)
            ->toContain('->logOnly([')
            ->toContain('->logOnlyDirty()')
            ->not->toContain('return LogOptions::defaults()->logAll();');
    }

    expect($device)
        ->not->toContain("'meta'")
        ->not->toContain("'data'")
        ->not->toContain("'last_position',");

    expect($sensor)
        ->not->toContain("'meta',")
        ->not->toContain("'last_position',");

    expect($deviceEvent)
        ->not->toContain("'payload',")
        ->not->toContain("'data',")
        ->not->toContain("'location',");
});

test('telematics setup renders endpoint overrides only in advanced settings', function () {
    $formTemplate      = file_get_contents(__DIR__ . '/../../addon/components/telematic/form.hbs');
    $settingsTemplate  = file_get_contents(__DIR__ . '/../../addon/components/telematic/settings.hbs');
    $formComponent     = file_get_contents(__DIR__ . '/../../addon/components/telematic/form.js');
    $settingsComponent = file_get_contents(__DIR__ . '/../../addon/components/telematic/settings.js');

    foreach ([$formComponent, $settingsComponent] as $component) {
        expect($component)
            ->toContain('advancedCredentialFields')
            ->toContain('field.advanced || field.is_endpoint')
            ->toContain('!field.advanced && !field.is_endpoint');
    }

    foreach ([$formTemplate, $settingsTemplate] as $template) {
        expect($template)
            ->toContain('Advanced connection settings')
            ->toContain('this.advancedCredentialFields')
            ->toContain('field.default_value');
    }
});

test('telematics details logs are routed and use persisted safe data only', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/TelematicController.php');

    expect($routes)
        ->toContain("'{id}/logs'")
        ->toContain("\$controller('logs')");

    expect($controller)
        ->toContain('public function logs(Request $request, string $id): JsonResponse')
        ->toContain('Activity::with([\'causer\'])')
        ->toContain("where('subject_type', Telematic::class)")
        ->toContain("where('subject_id', \$telematic->uuid)")
        ->toContain("where('company_uuid', session('company'))")
        ->toContain('Provider connection details were updated.')
        ->toContain('makeTelematicMetadataLogs')
        ->toContain('userFacingIssueMessage')
        ->toContain('isSensitiveIssueMessage')
        ->not->toContain('changed_fields')
        ->not->toContain('Str::headline')
        ->not->toContain('storage_path')
        ->not->toContain('Log::');
});

test('telematics provider status display maps active and connected to connected', function () {
    $indexController   = file_get_contents(__DIR__ . '/../../addon/controllers/connectivity/telematics/index.js');
    $detailsController = file_get_contents(__DIR__ . '/../../addon/controllers/connectivity/telematics/details.js');
    $statusCell        = file_get_contents(__DIR__ . '/../../addon/components/cell/telematic-status.js');

    expect($indexController)
        ->toContain("cellComponent: 'cell/telematic-status'");

    expect($detailsController)
        ->toContain("case 'active':\n                return 'Connected';");

    expect($statusCell)
        ->toContain("case 'active':")
        ->toContain("case 'connected':")
        ->toContain("return 'Connected';");
});

test('telematics overview attention items render as full width stacked alerts', function () {
    $template = file_get_contents(__DIR__ . '/../../addon/components/telematic/details.hbs');

    expect($template)
        ->toContain('<section class="flex flex-col gap-3">')
        ->toContain('class="w-full rounded-md border border-yellow-200 bg-yellow-50 p-3')
        ->not->toContain('lg:grid-cols-2');
});

test('device attachment morph types are normalized and legacy aliases are tolerated', function () {
    $migration       = file_get_contents(__DIR__ . '/../migrations/2026_06_15_000001_normalize_device_attachable_vehicle_morph_types.php');
    $command         = file_get_contents(__DIR__ . '/../src/Console/Commands/FixInvalidPolymorphicRelationTypeNamespaces.php');
    $serviceProvider = file_get_contents(__DIR__ . '/../src/Providers/FleetOpsServiceProvider.php');

    expect($migration)
        ->toContain("whereNotNull('attachable_uuid')")
        ->toContain("'Fleetbase\\\\Models\\\\Vehicle'")
        ->toContain("'\\\\Fleetbase\\\\Models\\\\Vehicle'")
        ->toContain("'attachable_type' => Vehicle::class")
        ->toContain('Intentionally do not restore invalid legacy morph class names.');

    expect($command)
        ->toContain('\\Fleetbase\\FleetOps\\Models\\Device::class')
        ->toContain("'columns' => ['attachable_type']");

    expect($serviceProvider)
        ->toContain('use Illuminate\Database\Eloquent\Relations\Relation;')
        ->toContain('$this->registerMorphMap();')
        ->toContain("'Fleetbase\\\\Models\\\\Vehicle'   => \\Fleetbase\\FleetOps\\Models\\Vehicle::class")
        ->toContain("'\\\\Fleetbase\\\\Models\\\\Vehicle' => \\Fleetbase\\FleetOps\\Models\\Vehicle::class");
});

test('internal device attachment endpoints return specific api errors instead of raw model misses', function () {
    $vehicleController = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/VehicleController.php');
    $deviceController  = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/DeviceController.php');

    expect($vehicleController)
        ->toContain('$vehicle  = $this->resolveVehicle($id);')
        ->toContain('$device   = $this->resolveDevice($deviceId);')
        ->toContain('Vehicle not found or not available for this organization.')
        ->toContain('Device not found or not available for this organization.')
        ->toContain('Unable to attach device to vehicle. Please try again or contact support.')
        ->toContain('Unable to detach device from vehicle. Please try again or contact support.')
        ->toContain('logDeviceAttachmentLookupFailure')
        ->toContain('logDeviceAttachmentFailure');

    expect($deviceController)
        ->toContain('$device    = $this->resolveDevice($id);')
        ->toContain('$vehicle   = $this->resolveVehicle($vehicleId);')
        ->toContain('Vehicle not found or not available for this organization.')
        ->toContain('Device not found or not available for this organization.')
        ->toContain('Unable to attach device to vehicle. Please try again or contact support.')
        ->toContain('Unable to detach device from vehicle. Please try again or contact support.')
        ->not->toContain('firstOrFail();');
});

function telematics_activity_log_method(string $model): string
{
    preg_match('/public function getActivitylogOptions\(\): LogOptions\s+\{(?P<body>.*?)\n    \}/s', $model, $matches);

    return $matches['body'] ?? '';
}
