<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Traits\app')) {
    eval('namespace Fleetbase\Traits; function app($abstract = null) { return new \stdClass(); }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Integrations\Lalamove\session')) {
    eval('namespace Fleetbase\FleetOps\Integrations\Lalamove; function session($key = null, $default = null) { return $key === "company" ? "company-session" : $default; }');
}

if (!function_exists('Fleetbase\\Support\\session')) {
    eval('namespace Fleetbase\\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \\session($k) !== null; } public function get($k, $d = null) { return \\session($k, $d); } }; } return \\session($key, $default); }');
}

use Fleetbase\FleetOps\Exceptions\IntegratedVendorException;
use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveMarket;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function fleetopsLalamoveLifecycleWithResponses(array $responses, array &$history = []): Lalamove
{
    $mock    = new MockHandler($responses);
    $history = [];
    $stack   = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new Client([
        'base_uri' => 'https://rest.sandbox.lalamove.com/v3/',
        'handler'  => $stack,
    ]);

    $lalamove = new Lalamove('api-key', 'api-secret', true, 'SG');
    $property = new ReflectionProperty($lalamove, 'client');
    $property->setAccessible(true);
    $property->setValue($lalamove, $client);

    return $lalamove;
}

function fleetopsLalamoveLifecycleProperty(object $target, string $property): mixed
{
    $reflection = new ReflectionProperty($target, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($target);
}

test('lalamove direct instances retain provided credentials and static dispatch forwards arguments in order', function () {
    $instance = Lalamove::instance('api-key', 'api-secret', true, 'SG');

    expect($instance)->toBeInstanceOf(Lalamove::class)
        ->and(fleetopsLalamoveLifecycleProperty($instance, 'apiKey'))->toBe('api-key')
        ->and(fleetopsLalamoveLifecycleProperty($instance, 'apiSecret'))->toBe('api-secret')
        ->and(fleetopsLalamoveLifecycleProperty($instance, 'isSandbox'))->toBeTrue();

    // Unknown proxied methods resolve an instance and return null.
    expect(Lalamove::fromHostMissingMethod())->toBeNull();
});

test('lalamove accepts market service type and integrated vendor objects', function () {
    $market           = LalamoveMarket::find('SG');
    $serviceType      = LalamoveServiceType::find('MOTORCYCLE');
    $integratedVendor = new IntegratedVendor();
    $integratedVendor->setRawAttributes([
        'uuid'      => 'integrated-vendor-uuid',
        'public_id' => 'vendor_public',
    ], true);
    $integratedVendor->setAppends([]);

    $lalamove = new Lalamove('api-key', 'api-secret', true, $market);

    expect(Lalamove::getMarket($market))->toBe($market)
        ->and(Lalamove::getServiceType($serviceType))->toBe($serviceType)
        ->and($lalamove->setMarket($market))->toBe($lalamove)
        ->and($lalamove->setIntegratedVendor($integratedVendor))->toBe($lalamove)
        ->and(fleetopsLalamoveLifecycleProperty($lalamove, 'integratedVendor'))->toBe($integratedVendor)
        ->and(fleetopsLalamoveLifecycleProperty($lalamove, 'market'))->toBe($market);
});

test('lalamove quotation requests include route options item cod and schedule data', function () {
    $history  = [];
    $lalamove = fleetopsLalamoveLifecycleWithResponses([
        new Response(200, [], json_encode(['data' => ['quotationId' => 'quotation-1']])),
    ], $history);

    $response = $lalamove->getQuotations(
        'MOTORCYCLE',
        [
            new Point(1.3001, 103.8002),
            new Point(1.4001, 103.9002),
        ],
        '2026-08-15T12:30:00+00:00',
        ['PURCHASE_SERVICE'],
        false,
        ['quantity' => '1', 'weight' => 'LESS_THAN_3KG'],
        ['amount'   => '1000'],
    );

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($response->data->quotationId)->toBe('quotation-1')
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toContain('/v3/quotations')
        ->and($history[0]['request']->getHeaderLine('Market'))->toBe('SG')
        ->and($body['data']['serviceType'])->toBe('MOTORCYCLE')
        ->and($body['data']['language'])->toBe('en_SG')
        ->and($body['data']['stops'][0]['coordinates'])->toBe(['lat' => '1.3001', 'lng' => '103.8002'])
        ->and($body['data']['isRouteOptimized'])->toBeFalse()
        ->and($body['data']['specialRequests'])->toBe(['PURCHASE_SERVICE'])
        ->and($body['data']['item'])->toBe(['quantity' => '1', 'weight' => 'LESS_THAN_3KG'])
        ->and($body['data']['cashOnDelivery'])->toBe(['amount' => '1000'])
        ->and($body['data']['scheduleAt'])->toBe('2026-08-15T12:30:00.000000Z');
});

test('lalamove normalizes contact senders and returns response data from order creation', function () {
    $history  = [];
    $lalamove = fleetopsLalamoveLifecycleWithResponses([
        new Response(200, [], json_encode(['data' => ['orderId' => 'order-1', 'status' => 'ASSIGNING_DRIVER']])),
    ], $history);

    $sender = new Contact();
    $sender->setRawAttributes([
        'name'  => 'Sender Contact',
        'phone' => '+18004444444',
    ], true);
    $sender->setAppends([]);

    $order = $lalamove->createOrder('quotation-1', $sender, [
        ['stopId' => 'dropoff', 'name' => 'Recipient', 'phone' => '+18005555555'],
    ]);

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($order)->toMatchObject([
        'orderId' => 'order-1',
        'status'  => 'ASSIGNING_DRIVER',
    ])
        ->and($body['data']['sender'])->toBe([
            'name'  => 'Sender Contact',
            'phone' => '+18004444444',
        ])
        ->and($body['data']['isRecipientSMSEnabled'])->toBeFalse()
        ->and($body['data']['isPODEnabled'])->toBeFalse()
        ->and($body['data']['metadata'])->toBe([]);
});

test('lalamove cancels fleetbase orders using integrated vendor order metadata', function () {
    $history  = [];
    $lalamove = fleetopsLalamoveLifecycleWithResponses([
        new Response(200, [], json_encode(['data' => ['orderId' => 'order-1', 'status' => 'CANCELED']])),
    ], $history);

    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
        'meta'      => [
            'integrated_vendor_order' => ['orderId' => 'order-1'],
        ],
    ], true);
    $order->setAppends([]);

    $response = $lalamove->cancelFromFleetbaseOrder($order);

    expect($response->data->status)->toBe('CANCELED')
        ->and($history[0]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[0]['request']->getUri())->toContain('/v3/orders/order-1');
});

test('lalamove webhook lifecycle ignores empty urls returns data and throws vendor errors', function () {
    $history  = [];
    $lalamove = fleetopsLalamoveLifecycleWithResponses([
        new Response(200, [], json_encode(['data' => ['url' => 'https://hooks.example/lalamove']])),
        new Response(200, [], json_encode([
            'errors' => [
                ['id' => 'WEBHOOK_ERROR', 'message' => 'Invalid webhook', 'detail' => 'URL rejected'],
            ],
        ])),
    ], $history);

    expect($lalamove->setWebhook())->toBeNull()
        ->and($history)->toBe([]);

    $webhook = $lalamove->setWebhook('https://hooks.example/lalamove');

    expect($webhook)->toMatchObject(['url' => 'https://hooks.example/lalamove'])
        ->and($history[0]['request']->getMethod())->toBe('PATCH')
        ->and((string) $history[0]['request']->getUri())->toContain('/v3/webhook')
        ->and(json_decode((string) $history[0]['request']->getBody(), true))->toBe([
            'data' => ['url' => 'https://hooks.example/lalamove'],
        ]);

    expect(fn () => $lalamove->setWebhook('https://hooks.example/rejected'))
        ->toThrow(IntegratedVendorException::class, 'Lalamove: WEBHOOK_ERROR Invalid webhook (URL rejected)');
});
