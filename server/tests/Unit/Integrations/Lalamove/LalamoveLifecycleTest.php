<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Exceptions\IntegratedVendorException;
use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveMarket;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
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

test('lalamove direct instances retain provided credentials while static dispatch exposes current argument ordering', function () {
    $instance = Lalamove::instance('api-key', 'api-secret', true, 'SG');

    expect($instance)->toBeInstanceOf(Lalamove::class)
        ->and(fleetopsLalamoveLifecycleProperty($instance, 'apiKey'))->toBe('api-key')
        ->and(fleetopsLalamoveLifecycleProperty($instance, 'apiSecret'))->toBe('api-secret');

    expect(fn () => Lalamove::fromHostMissingMethod())
        ->toThrow(TypeError::class, 'Argument #3 ($sandbox) must be of type bool');
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
