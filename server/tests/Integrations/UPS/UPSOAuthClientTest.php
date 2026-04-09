<?php

use Fleetbase\FleetOps\Integrations\UPS\UPSOAuthClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class UPSOAuthTestHarness
{
    public UPSOAuthClient $client;
    public array $history = [];
    public \ArrayObject $cache;

    public function __construct(string $clientId, string $clientSecret, bool $sandbox, array $responses)
    {
        $mock = new MockHandler(array_map(
            fn ($payload) => new Response(
                $payload['status'] ?? 200,
                ['Content-Type' => 'application/json'],
                json_encode($payload['body'] ?? [])
            ),
            $responses
        ));

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $this->cache  = new \ArrayObject();
        $this->client = new UPSOAuthClient($clientId, $clientSecret, $sandbox, $stack, $this->cache);
    }
}

// ── Host selection ───────────────────────────────────────────────────────

test('sandbox flag routes to wwwcie.ups.com', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['access_token' => 't', 'expires_in' => 3600]],
    ]);
    $h->client->getAccessToken();

    expect((string) $h->history[0]['request']->getUri())
        ->toStartWith('https://wwwcie.ups.com/security/v1/oauth/token');
});

test('production flag routes to onlinetools.ups.com', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', false, [
        ['status' => 200, 'body' => ['access_token' => 't', 'expires_in' => 3600]],
    ]);
    $h->client->getAccessToken();

    expect((string) $h->history[0]['request']->getUri())
        ->toStartWith('https://onlinetools.ups.com/security/v1/oauth/token');
});

// ── Token fetch ──────────────────────────────────────────────────────────

test('first call fetches token from UPS and stores it', function () {
    $h = new UPSOAuthTestHarness('my-client', 'my-secret', true, [
        ['status' => 200, 'body' => ['access_token' => 'abc-token', 'expires_in' => 3600]],
    ]);

    $token = $h->client->getAccessToken();

    expect($token)->toBe('abc-token');
    expect($h->cache['ups_oauth_token_my-client'])->toBe('abc-token');
});

test('request uses HTTP Basic auth with client_id and client_secret', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['access_token' => 't', 'expires_in' => 3600]],
    ]);
    $h->client->getAccessToken();

    $auth = $h->history[0]['request']->getHeaderLine('Authorization');
    expect($auth)->toBe('Basic ' . base64_encode('cid:csec'));
});

test('request body is grant_type=client_credentials form-encoded', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['access_token' => 't', 'expires_in' => 3600]],
    ]);
    $h->client->getAccessToken();

    $req = $h->history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/x-www-form-urlencoded');
    expect((string) $req->getBody())->toBe('grant_type=client_credentials');
});

// ── Cache short-circuit ──────────────────────────────────────────────────

test('second call within TTL returns cached token without HTTP', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['access_token' => 'first-token', 'expires_in' => 3600]],
    ]);

    $first  = $h->client->getAccessToken();
    $second = $h->client->getAccessToken();

    expect($first)->toBe('first-token');
    expect($second)->toBe('first-token');
    expect($h->history)->toHaveCount(1);
});

test('pre-populated cache entry short-circuits the HTTP call entirely', function () {
    $h = new UPSOAuthTestHarness('pre-cid', 'csec', true, []);
    $h->cache['ups_oauth_token_pre-cid'] = 'precached-token';

    $token = $h->client->getAccessToken();

    expect($token)->toBe('precached-token');
    expect($h->history)->toHaveCount(0);
});

// ── TTL safety margin ────────────────────────────────────────────────────

test('getLastTtl returns expires_in minus 60 seconds', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['access_token' => 't', 'expires_in' => 3600]],
    ]);
    $h->client->getAccessToken();

    expect($h->client->getLastCachedTtl())->toBe(3540);
});

test('getLastTtl clamps to minimum 60 seconds when expires_in is very small', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['access_token' => 't', 'expires_in' => 30]],
    ]);
    $h->client->getAccessToken();

    expect($h->client->getLastCachedTtl())->toBeGreaterThanOrEqual(60);
});

// ── Cache key scoping ────────────────────────────────────────────────────

test('cache key is namespaced by clientId so multi-tenant accounts do not collide', function () {
    $a = new UPSOAuthTestHarness('tenant-a', 's', true, [
        ['status' => 200, 'body' => ['access_token' => 'token-a', 'expires_in' => 3600]],
    ]);
    $b = new UPSOAuthTestHarness('tenant-b', 's', true, [
        ['status' => 200, 'body' => ['access_token' => 'token-b', 'expires_in' => 3600]],
    ]);

    $a->client->getAccessToken();
    $b->client->getAccessToken();

    expect($a->cache['ups_oauth_token_tenant-a'])->toBe('token-a');
    expect($b->cache['ups_oauth_token_tenant-b'])->toBe('token-b');
});

// ── Error handling ───────────────────────────────────────────────────────

test('non-2xx response throws a RuntimeException', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 401, 'body' => ['error' => 'invalid_client']],
    ]);

    expect(fn () => $h->client->getAccessToken())->toThrow(RuntimeException::class);
});

test('missing access_token in response throws a RuntimeException', function () {
    $h = new UPSOAuthTestHarness('cid', 'csec', true, [
        ['status' => 200, 'body' => ['expires_in' => 3600]],
    ]);

    expect(fn () => $h->client->getAccessToken())->toThrow(RuntimeException::class);
});
