<?php

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Support\Telematics\Providers\SafeeProvider;
use Fleetbase\FleetOps\Support\Telematics\Safee\Transport;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Sample;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class SafeeContractTransport extends Transport
{
    public static float $clock = 1000;

    public function reserve(): void
    {
        $this->reserveRequest(self::$clock + 5);
    }

    protected function time(): float
    {
        return self::$clock;
    }

    protected function wallTime(): float
    {
        return self::$clock;
    }
}

class SafeeLiveContractProvider extends SafeeProvider
{
    public function credentials(array $credentials): void
    {
        $this->credentials = $credentials;
        $this->prepareAuthentication();
    }

    public function tokenCacheKey(): string
    {
        return 'safee:token:' . $this->transport()->fingerprint();
    }

    protected function transport(): Transport
    {
        return $this->transport ??= new SafeeContractTransport($this->baseUrl, $this->credentials);
    }

    protected function requestTime(): float
    {
        return SafeeContractTransport::$clock;
    }
}

function safeeLiveProvider(array $credentials = []): SafeeLiveContractProvider
{
    $provider = new SafeeLiveContractProvider();
    $provider->credentials(array_replace(['server_uri' => 'https://safee.contract.test', 'access_token' => 'fixture-token'], $credentials));

    return $provider;
}

function safeeLiveCredentials(): array
{
    return ['access_token' => null, 'realm_id' => 'fixture', 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'username' => 'fixture-user', 'password' => 'fixture-password'];
}

function safeeLiveState(int $id, array $overrides = []): array
{
    return array_replace(['id' => 90000 + $id, 'vehicle' => ['id' => $id], 'date' => '2026-09-17T10:00:00Z', 'position' => ['lat' => 24, 'lon' => 46, 'alt' => 615], 'speed' => 12, 'heading' => 90, 'odometer' => '1234.5'], $overrides);
}

beforeEach(function () {
    app()->instance('encrypter', new Encrypter(str_repeat('s', 32), 'aes-256-cbc'));
    Crypt::clearResolvedInstance('encrypter');
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory());
    SafeeContractTransport::$clock = 1000;
    Carbon::setTestNow('2026-09-17 10:01:00 UTC');
});

afterEach(fn () => Carbon::setTestNow());

test('safee live polling uses documented inventory and batches all vehicle ids without history requests', function () {
    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request;
        if (str_ends_with($request->url(), '/list-info')) {
            expect($request->body())->toBe('{}');

            return Http::response(['code' => 0, 'result' => array_map(fn ($id) => ['id' => $id, 'plateNo' => 'Truck ' . $id], range(1, 2001))]);
        }
        expect($request->url())->toEndWith('/last-state');
        expect(array_keys($request->data()))->toBe(['live', 'endDate', 'vehicles']);
        expect($request['live'])->toBeTrue()->and($request['endDate'])->toBeNull();
        expect(count($request['vehicles']))->toBeLessThanOrEqual(1000);

        return Http::response(['code' => 0, 'time' => 9999999999, 'result' => array_map('safeeLiveState', $request['vehicles'])]);
    });
    $provider = safeeLiveProvider();
    $cursor   = null;
    $ids      = [];
    do {
        $page   = $provider->fetchDevices(['cursor' => $cursor, 'limit' => 5000]);
        $ids    = array_merge($ids, array_map(fn ($unit) => $provider->normalizeTelemetrySnapshot($unit)['device']['device_id'], $page['devices']));
        $cursor = $page['next_cursor'];
    } while ($page['has_more']);
    expect($ids)->toBe(range(1, 2001))->and($page['pagination']['allCount'])->toBe(2001);
    Http::assertSentCount(4);
    expect($provider->telemetryOptions())->toMatchArray(['polling_enabled' => true, 'manual_batch_sync' => true, 'webhooks_enabled' => false]);
    expect($provider->supportsWebhooks())->toBeFalse();
});

test('safee caches inventory per credentials and manual refresh replaces it', function () {
    $inventoryCalls = 0;
    Http::fake(function ($request) use (&$inventoryCalls) {
        if (str_ends_with($request->url(), '/list-info')) {
            $inventoryCalls++;

            return Http::response(['code' => 0, 'result' => [['id' => 1, 'plateNo' => 'revision-' . $inventoryCalls]]]);
        }

        return Http::response(['code' => 0, 'result' => [safeeLiveState(1)]]);
    });
    expect(safeeLiveProvider()->fetchDevices()['devices'][0]['plateNo'])->toBe('revision-1');
    expect(safeeLiveProvider()->fetchDevices()['devices'][0]['plateNo'])->toBe('revision-1');
    expect(safeeLiveProvider()->fetchDevices(['refresh_inventory' => true])['devices'][0]['plateNo'])->toBe('revision-2');
    expect(safeeLiveProvider(['access_token' => 'changed'])->fetchDevices()['devices'][0]['plateNo'])->toBe('revision-3');
    expect($inventoryCalls)->toBe(3);
});

test('safee rejects malformed or unsuccessful fleet responses instead of returning an empty success', function ($response) {
    Http::fakeSequence()->push($response);
    expect(fn () => safeeLiveProvider()->fetchDevices())->toThrow(TelematicProviderException::class);
})->with([
    'non-json'           => ['not json'],
    'error envelope'     => [['code' => 4, 'result' => []]],
    'missing code'       => [['result' => []]],
    'missing result'     => [['code' => 0]],
    'object result'      => [['code' => 0, 'result' => ['id' => 1]]],
    'scalar record'      => [['code' => 0, 'result' => [false]]],
    'missing identity'   => [['code' => 0, 'result' => [['plateNo' => 'unknown']]]],
    'duplicate identity' => [['code' => 0, 'result' => [['id' => 1], ['id' => 1]]]],
]);

test('safee accepts an explicitly empty fleet and does not request states', function () {
    Http::fakeSequence()->push(['code' => 0, 'result' => []]);
    expect(safeeLiveProvider()->fetchDevices())->toMatchArray(['devices' => [], 'next_cursor' => null, 'has_more' => false]);
    Http::assertSentCount(1);
});

test('safee isolates missing states while retaining both valid neighbors and canonical inventory identity', function () {
    Http::fakeSequence()->push(['code' => 0, 'result' => [['id' => 1], ['id' => 2], ['id' => 3]]])
        ->push(['code' => 0, 'result' => [safeeLiveState(1), safeeLiveState(3)]]);
    $provider = safeeLiveProvider();
    $page     = $provider->fetchDevices();
    $samples  = array_map([$provider, 'normalizeTelemetrySnapshot'], $page['devices']);
    expect(array_column(array_column($samples, 'device'), 'device_id'))->toBe([1, 2, 3]);
    expect(array_map(fn ($sample) => Sample::validPosition($sample['event']), $samples))->toBe([true, false, true]);
    expect($page['sync_meta']['safee_last_endpoint_counts']['missing_states'])->toBe(1);
});

test('safee refuses state responses that cannot be safely matched to the requested inventory', function ($states) {
    Http::fakeSequence()->push(['code' => 0, 'result' => [['id' => 1]]])->push(['code' => 0, 'result' => $states]);
    expect(fn () => safeeLiveProvider()->fetchDevices())->toThrow(TelematicProviderException::class);
})->with([
    'wrong vehicle'     => [[['vehicleId' => 2]]],
    'duplicate vehicle' => [[['vehicleId' => 1], ['vehicleId' => 1]]],
    'missing identity'  => [[['date' => 1]]],
    'scalar state'      => [[42]],
]);

test('safee normalizes UTC source time altitude and counters without inventing provider time or missing metadata', function () {
    $provider = safeeLiveProvider();
    $payload  = ['_safee' => ['vehicle_id' => 1, 'identity' => ['id' => 1], 'current_state' => safeeLiveState(1, ['date' => '2026-09-17T13:00:00.125+03:00', 'speed' => 0, 'temperature' => ['Cargo' => 0]])]];
    $sample   = $provider->normalizeTelemetrySnapshot($payload);
    expect($sample['event'])->toMatchArray(['device_id' => 1, 'occurred_at' => '2026-09-17T10:00:00.125000Z', 'last_seen_at' => '2026-09-17T10:00:00.125000Z', 'altitude' => 615, 'odometer' => '1234.5', 'speed' => 0]);
    expect($sample['device'])->not->toHaveKeys(['name', 'imei', 'status', 'online']);
    expect(data_get($sample, 'event.meta.telemetry.provider_at'))->toBeNull();
    expect($sample['sensors'][0])->toMatchArray(['internal_id' => 'safee:1:temperature:Cargo', 'value' => 0, 'recorded_at' => '2026-09-17T10:00:00.125000Z']);
    $payload['_safee']['current_info'] = safeeLiveState(1, ['date' => '2026-09-16T10:00:00Z', 'position' => ['lat' => 1, 'lon' => 2], 'speed' => 80]);
    $latest                            = $provider->normalizeTelemetrySnapshot($payload);
    expect($latest['event']['location'])->toBe(['lat' => 24, 'lng' => 46])->and($latest['event']['speed'])->toBe(0);
});

test('safee does not invent timestamps or overwrite omitted counters in a partial snapshot', function () {
    $provider = safeeLiveProvider();
    $sample   = $provider->normalizeTelemetrySnapshot(['vehicleId' => 1, 'position' => ['lat' => 24, 'lon' => 46], 'date' => 'invalid']);
    expect(Sample::validPosition($sample['event']))->toBeFalse();
    expect($sample['event']['occurred_at'])->toBeNull();
    expect($sample['device']['meta'])->not->toHaveKeys(['driver', 'plate_number']);
    expect($sample['sensors'])->toBe([]);
    expect($provider->normalizeTelemetrySnapshot(['id' => 1, 'date' => 0, 'position' => ['lat' => 0, 'lon' => 0]])['event']['occurred_at'])->toBeNull();
    expect(fn () => $provider->telemetryUnits([safeeLiveState(1)]))->toThrow(InvalidArgumentException::class);
});

test('safee token cache is encrypted reusable credential scoped and recovers from foreign encryption', function () {
    $authCalls = 0;
    Http::fake(function ($request) use (&$authCalls) {
        if (str_contains($request->url(), '/openid-connect/token')) {
            $authCalls++;
            expect($request['grant_type'])->toBe('password');

            return Http::response(['access_token' => 'secret-token-' . $authCalls, 'expires_in' => 300]);
        }

        return Http::response(['code' => 0, 'result' => []]);
    });
    $a = safeeLiveProvider(safeeLiveCredentials());
    Http::assertNothingSent();
    $a->fetchDevices();
    $stored = Cache::get($a->tokenCacheKey());
    expect($stored)->not->toContain('secret-token');
    expect(json_decode(Crypt::decryptString($stored), true)['access_token'])->toBe('secret-token-1');
    safeeLiveProvider(safeeLiveCredentials())->fetchDevices(['refresh_inventory' => true]);
    expect($authCalls)->toBe(1);
    Cache::put($a->tokenCacheKey(), (new Encrypter(str_repeat('x', 32), 'aes-256-cbc'))->encryptString('foreign'), 60);
    safeeLiveProvider(safeeLiveCredentials())->fetchDevices(['refresh_inventory' => true]);
    safeeLiveProvider(array_replace(safeeLiveCredentials(), ['password' => 'changed']))->fetchDevices();
    expect($authCalls)->toBe(3);
});

test('safee refreshes a rejected token once using the documented refresh grant', function () {
    Http::fakeSequence()->push(['access_token' => 'old', 'refresh_token' => 'refresh-value', 'expires_in' => 300])
        ->push([], 401)->push(['access_token' => 'new', 'expires_in' => 300])
        ->push(['code' => 0, 'result' => []]);
    safeeLiveProvider(safeeLiveCredentials())->fetchDevices();
    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => ($request->data()['grant_type'] ?? null) === 'refresh_token' && ($request->data()['refresh_token'] ?? null) === 'refresh-value');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/list-info') && $request->hasHeader('Authorization', 'Bearer new'));
});

test('safee stops after a second token rejection', function () {
    Http::fakeSequence()->push(['access_token' => 'old', 'expires_in' => 300])->push([], 401)
        ->push(['access_token' => 'new', 'expires_in' => 300])->push([], 401);
    expect(fn () => safeeLiveProvider(safeeLiveCredentials())->fetchDevices())->toThrow(TelematicProviderException::class, '401');
    Http::assertSentCount(4);
});

test('safee refreshes tokens before their documented expiry and falls back once for revoked refresh tokens', function () {
    Http::fakeSequence()->push(['access_token' => 'old', 'refresh_token' => 'revoked-refresh', 'expires_in' => 300, 'refresh_expires_in' => 600])
        ->push(['code' => 0, 'result' => []])->push([], 400)
        ->push(['access_token' => 'new', 'expires_in' => 300])->push(['code' => 0, 'result' => []]);
    $provider = safeeLiveProvider(safeeLiveCredentials());
    $provider->fetchDevices();
    SafeeContractTransport::$clock += 296;
    $provider->fetchDevices(['refresh_inventory' => true]);
    $grants = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['grant_type'] ?? null)->filter()->values()->all();
    expect($grants)->toBe(['password', 'refresh_token', 'password']);
    Http::assertSentCount(5);
});

test('safee enforces a shared atomic fifty per second account budget including credential variants', function () {
    $a = new SafeeContractTransport('https://safee.contract.test', safeeLiveCredentials());
    $b = new SafeeContractTransport('https://safee.contract.test', array_replace(safeeLiveCredentials(), ['password' => 'changed']));
    for ($i = 0; $i < 50; $i++) {
        ($i % 2 ? $a : $b)->reserve();
    }
    expect(fn () => $a->reserve())->toThrow(TelematicRateLimitExceededException::class);
    SafeeContractTransport::$clock += 1.01;
    $b->reserve();
});

test('safee respects Retry-After across instances without issuing more requests', function () {
    Http::fakeSequence()->push([], 429, ['Retry-After' => '12']);
    foreach (range(1, 2) as $attempt) {
        try {
            safeeLiveProvider()->fetchDevices();
            test()->fail('Expected rate limiting.');
        } catch (TelematicRateLimitExceededException $e) {
            expect($e->context()['retry_after'])->toBe(12);
        }
    }
    Http::assertSentCount(1);
});

test('safee page deadline includes authentication inventory and state retrieval', function () {
    $timeouts = [];
    Http::fake(function ($request, $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];
        expect($options['connect_timeout'])->toBeLessThanOrEqual(3);
        SafeeContractTransport::$clock += 6;
        if (str_contains($request->url(), '/openid-connect/token')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 300]);
        }

        return Http::response(['code' => 0, 'result' => [['id' => 1]]]);
    });
    expect(fn () => safeeLiveProvider(safeeLiveCredentials())->fetchDevices(['timeout' => 10, 'connect_timeout' => 3]))->toThrow(TelematicProviderException::class, 'time budget');
    expect($timeouts)->toBe([10, 4]);
    Http::assertSentCount(2);
});

test('safee historical failures do not advance the global history checkpoint', function () {
    Http::fakeSequence()->push(['code' => 0, 'result' => ['vehicleId' => 1]])->push([], 503)->push(['code' => 0, 'result' => []]);
    $result = safeeLiveProvider()->fetchDeviceTelemetrySnapshots([['id' => 1]], ['start_date' => 100, 'end_date' => 200]);
    expect($result['sync_meta'])->not->toHaveKey('safee_last_telemetry_synced_at');
    expect($result['sync_meta']['safee_last_enrichment_failures'])->toHaveCount(1);
});

test('safee rejects invalid inventory cursors and unsupported list filters before paging', function () {
    Http::fake([
        '*/list-info'  => Http::response(['code' => 0, 'result' => [['id' => 1], ['id' => 2]]]),
        '*/last-state' => Http::response(['code' => 0, 'result' => []]),
    ]);
    $provider = safeeLiveProvider();
    expect(fn () => $provider->fetchDevices(['cursor' => 'next']))->toThrow(TelematicProviderException::class, 'cursor is invalid')
        ->and(fn () => $provider->fetchDevices(['cursor' => -1]))->toThrow(TelematicProviderException::class, 'cursor is invalid')
        ->and(fn () => $provider->fetchDevices(['filters' => ['status' => 'ACTIVE']]))->toThrow(TelematicProviderException::class, 'does not support filters')
        ->and(fn () => $provider->fetchDevices(['cursor' => 3]))->toThrow(TelematicProviderException::class, 'exceeds the inventory size');
    // Only the out-of-range cursor needed the inventory; invalid input fails before any request.
    Http::assertSentCount(1);
});

test('safee reports data and authentication connection failures without leaking transport details', function (bool $authenticate) {
    Http::fake(fn ($request) => throw new GuzzleHttp\Exception\ConnectException('SSL connection timeout', new GuzzleHttp\Psr7\Request('POST', (string) $request->url())));
    $provider = safeeLiveProvider($authenticate ? safeeLiveCredentials() : []);
    $message  = $authenticate ? 'Safee authentication timed out or could not connect.' : 'Safee request timed out or could not connect.';
    expect(fn () => $provider->fetchDevices())->toThrow(TelematicProviderException::class, $message);
})->with(['data request' => [false], 'token request' => [true]]);

test('safee does not refresh a rejected static token without password grant credentials', function () {
    Http::fake(['*' => Http::response([], 401)]);
    expect(fn () => safeeLiveProvider()->fetchDevices())->toThrow(TelematicProviderException::class, '401');
    Http::assertSentCount(1);
});

test('safee requires realm client and user credentials when no access token is available', function () {
    Http::fake();
    expect(fn () => safeeLiveProvider(['access_token' => null])->fetchDevices())->toThrow(InvalidArgumentException::class, 'credentials are required');
    Http::assertNothingSent();
});
