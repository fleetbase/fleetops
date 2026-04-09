<?php

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class PPTestHarness
{
    public ParcelPath $bridge;
    public array $history = [];

    public function __construct(array $responses = [])
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

        $this->bridge = new ParcelPath('test-key', true, $stack);
    }
}

// ── Constructor / URL ────────────────────────────────────────────────────

test('constructor stores api key and sandbox flag', function () {
    $bridge = new ParcelPath('my-key', true);
    expect($bridge->getApiKey())->toBe('my-key');
    expect($bridge->isSandbox())->toBeTrue();
});

test('sandbox false uses production host', function () {
    $bridge = new ParcelPath('k', false);
    expect($bridge->buildRequestUrl())->toBe('https://api.parcelpath.com/v1/');
});

test('sandbox true uses sandbox host', function () {
    $bridge = new ParcelPath('k', true);
    expect($bridge->buildRequestUrl())->toBe('https://api-sandbox.parcelpath.com/v1/');
});

test('buildRequestUrl appends path segment', function () {
    $bridge = new ParcelPath('k', true);
    expect($bridge->buildRequestUrl('rates'))->toBe('https://api-sandbox.parcelpath.com/v1/rates');
});

// ── Chainable setters ────────────────────────────────────────────────────

test('setRequestId is chainable and stores value', function () {
    $bridge = new ParcelPath('k');
    expect($bridge->setRequestId('req-123'))->toBe($bridge);
});

test('setOptions merges with existing and is chainable', function () {
    $bridge = new ParcelPath('k');
    $bridge->setOptions(['carrier_filter' => 'ups', 'label_format' => 'PDF']);
    $bridge->setOptions(['label_format' => 'ZPL']);
    expect($bridge->getOptions())->toBe([
        'carrier_filter' => 'ups',
        'label_format'   => 'ZPL',
    ]);
});

test('setOptions tolerates null', function () {
    $bridge = new ParcelPath('k');
    $bridge->setOptions(null);
    expect($bridge->getOptions())->toBe([]);
});

// ── HTTP layer via Guzzle MockHandler ────────────────────────────────────

test('post request sends bearer token and json content type', function () {
    $h = new PPTestHarness([
        ['status' => 200, 'body' => ['ok' => true]],
    ]);

    $result = $h->bridge->post('rates', ['json' => ['x' => 1]]);

    expect($result)->toBe(['ok' => true]);
    expect($h->history)->toHaveCount(1);
    $request = $h->history[0]['request'];
    expect($request->getMethod())->toBe('POST');
    expect($request->getUri()->getPath())->toBe('/v1/rates');
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer test-key');
    expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
    expect($request->getHeaderLine('Accept'))->toBe('application/json');
    expect((string) $request->getBody())->toBe('{"x":1}');
});

test('request id is propagated as X-Request-Id header when set', function () {
    $h = new PPTestHarness([
        ['status' => 200, 'body' => []],
    ]);

    $h->bridge->setRequestId('req-abc');
    $h->bridge->get('tracking/1Z123');

    $request = $h->history[0]['request'];
    expect($request->getHeaderLine('X-Request-Id'))->toBe('req-abc');
});

test('request id header is absent when not set', function () {
    $h = new PPTestHarness([
        ['status' => 200, 'body' => []],
    ]);

    $h->bridge->get('tracking/1Z123');

    $request = $h->history[0]['request'];
    expect($request->hasHeader('X-Request-Id'))->toBeFalse();
});

test('delete returns parsed json', function () {
    $h = new PPTestHarness([
        ['status' => 200, 'body' => ['voided' => true]],
    ]);

    $result = $h->bridge->delete('shipments/pp_ship_1');
    expect($result)->toBe(['voided' => true]);
    expect($h->history[0]['request']->getMethod())->toBe('DELETE');
});

test('non-2xx response still returns parsed body without throwing', function () {
    $h = new PPTestHarness([
        ['status' => 422, 'body' => ['error' => 'invalid_address']],
    ]);

    $result = $h->bridge->post('labels', ['json' => []]);
    expect($result)->toBe(['error' => 'invalid_address']);
});
