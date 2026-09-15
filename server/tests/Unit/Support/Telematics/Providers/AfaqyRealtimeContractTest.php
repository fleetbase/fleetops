<?php

require_once __DIR__ . '/../../../../Support/AfaqyTestCrypto.php';

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Payload;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class AfaqyRealtimeProbe extends AfaqyProvider
{
    public function credentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function call(string $method, ...$args): mixed
    {
        return $this->{$method}(...$args);
    }
}

beforeEach(function () {
    app()->instance('encrypter', afaqyTestCrypto());
    Crypt::clearResolvedInstance('encrypter');
    Cache::flush();
    Carbon::setTestNow('2026-09-15 12:00:00 UTC');
    Http::swap(new Illuminate\Http\Client\Factory());
});

afterEach(fn () => Carbon::setTestNow());

function afaqyRealtimeUnit(): array
{
    return ['_id' => 'unit-1', 'data' => ['name' => 'Truck', 'active' => true, 'last_update' => [
        'dtt' => '2026-09-15 11:59:00', 'dts' => '2026-09-15 11:59:10', 'lat' => 0, 'lng' => 46.7, 'spd' => 15, 'ang' => 90, 'acc' => 1,
    ]]];
}

test('provisional webhook accepts flat nested single and batch units without authenticating', function () {
    $provider = new AfaqyProvider();
    $nested   = afaqyRealtimeUnit();
    $flat     = Payload::unit($nested);
    foreach ([$nested, $flat, [$nested, $flat], ['data' => [$nested]], ['data' => $flat]] as $body) {
        $result = $provider->processWebhook($body);
        expect($result['events'][0])->toMatchArray(['device_id' => 'unit-1', 'occurred_at' => '2026-09-15T11:59:00.000000Z', 'last_seen_at' => '2026-09-15T11:59:10.000000Z', 'ignition' => true, 'online' => null]);
        expect(Payload::validPosition($result['events'][0]))->toBeTrue();
    }
    Http::assertNothingSent();
    foreach ([[], ['event_type' => 'overspeed'], ['data' => [['name' => 'No identity']]]] as $body) {
        expect(fn () => Payload::units($body))->toThrow(InvalidArgumentException::class);
    }
});

test('timestamps are explicit UTC and malformed or future positions cannot look fresh', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Riyadh');
    try {
        expect(Payload::timestamp('2026-09-15 11:59:00'))->toBe(Payload::timestamp('2026-09-15T14:59:00+03:00'));
        expect(Payload::timestamp(1757937600000))->toBe(Payload::timestamp(1757937600));
        expect(Payload::timestamp('nonsense'))->toBeNull();
        $event = (new AfaqyProvider())->normalizeEvent(afaqyRealtimeUnit());
        expect(Payload::validPosition($event))->toBeTrue();
        foreach ([['location' => ['lat' => 91, 'lng' => 0]], ['location' => ['lat' => 'bad', 'lng' => 0]], ['occurred_at' => null], ['occurred_at' => '2026-09-16T00:00:00Z']] as $invalid) {
            expect(Payload::validPosition(array_replace($event, $invalid)))->toBeFalse();
        }
    } finally {
        date_default_timezone_set($timezone);
    }
});

test('poll and webhook signal identities ignore envelope and receipt differences', function () {
    $provider                 = new AfaqyProvider();
    $a                        = $provider->normalizeEvent(afaqyRealtimeUnit());
    $b                        = $provider->normalizeEvent(Payload::unit(afaqyRealtimeUnit()));
    $b['meta']['received_at'] = 'other';
    $b['speed']               = '15';
    expect(Payload::signalKey($a))->toBe(Payload::signalKey($b));
    $b['location']['lat'] = 1;
    expect(Payload::signalKey($a))->not->toBe(Payload::signalKey($b));
});

test('units requests use documented groups and traverse a five thousand unit fleet', function () {
    Http::fake(function ($request) {
        $body = $request->data()['data'];
        expect($body)->toMatchArray(['simplify' => 0, 'limit' => 1000, 'projection' => ['basic', 'last_update']]);
        $offset = $body['offset'];
        $units  = array_map(fn ($i) => ['_id' => 'u-' . $i, 'data' => ['last_update' => ['dtt' => '2026-09-15 11:59:00']]], range($offset, $offset + 999));

        return Http::response(['data' => $units, 'pagination' => ['allCount' => 5000, 'offset' => $offset, 'limit' => 1000, 'resultCount' => 1000]]);
    });
    $provider = new AfaqyRealtimeProbe();
    $provider->credentials(['token' => 'contract-token']);
    $cursor = null;
    $count  = 0;
    do {
        $response = $provider->fetchDevices(['cursor' => $cursor]);
        $count += count($response['devices']);
        $cursor = $response['next_cursor'];
    } while ($response['has_more']);
    expect($count)->toBe(5000);
    Http::assertSentCount(5);
});

test('missing totals continue full pages and malformed envelopes fail explicitly', function () {
    Http::fakeSequence()->push(['data' => [['_id' => 'u1'], ['_id' => 'u2']]])->push(['data' => []])->push(['unexpected' => []]);
    $provider = new AfaqyRealtimeProbe();
    $provider->credentials(['token' => 'contract-token']);
    expect($provider->fetchDevices(['limit' => 2])['next_cursor'])->toBe(2);
    expect($provider->fetchDevices(['limit' => 2, 'cursor' => 2])['has_more'])->toBeFalse();
    expect(fn () => $provider->fetchDevices())->toThrow(TelematicProviderException::class);
});

test('tokens are reused encrypted and credential changes invalidate the cache namespace', function () {
    Http::fake(['*/auth/login' => Http::response(['data' => ['token' => 'fresh']])]);
    foreach (['pw', 'pw', 'changed'] as $password) {
        $provider = new AfaqyRealtimeProbe();
        $provider->credentials(['username' => 'same-user', 'password' => $password]);
        $provider->call('prepareAuthentication');
    }
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => !isset($request->data()['data']['expire']));
});

test('request limit is shared across provider instances and Retry-After blocks retries', function () {
    $a = new AfaqyRealtimeProbe();
    $b = new AfaqyRealtimeProbe();
    foreach ([$a, $b] as $provider) {
        $provider->credentials(['username' => 'rate-user', 'password' => 'pw']);
    }
    for ($i = 0; $i < 60; $i++) {
        ($i % 2 ? $a : $b)->call('reserveRequest');
    }
    expect(fn () => $a->call('reserveRequest'))->toThrow(TelematicRateLimitExceededException::class);
    Cache::flush();
    Http::fakeSequence()->push([], 429, ['Retry-After' => '25']);
    try {
        $a->call('afaqyPost', '/units/lists');
        test()->fail('Expected throttling.');
    } catch (TelematicRateLimitExceededException $e) {
        expect($e->context()['retry_after'])->toBe(25);
    }
    expect(fn () => $b->call('reserveRequest'))->toThrow(TelematicRateLimitExceededException::class);
    Http::assertSentCount(1);
});
