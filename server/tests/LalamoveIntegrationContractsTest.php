<?php

use Fleetbase\FleetOps\Exceptions\IntegratedVendorException;
use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveDeliveryStop;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveMarket;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType;
use Fleetbase\FleetOps\Models\ServiceQuote;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function fleetOpsLalamoveWithResponses(array $responses, array &$history = []): Lalamove
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

function fleetOpsLalamoveInvoke(object $target, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($target, $arguments);
}

test('lalamove value objects resolve markets service types stops and cents', function () {
    $market      = LalamoveMarket::find('sg');
    $serviceType = LalamoveServiceType::find('motorcycle');
    $stop        = new LalamoveDeliveryStop(1.25, 103.75, 'Depot');

    expect($market)->toBeInstanceOf(LalamoveMarket::class)
        ->and($market->getCode())->toBe('SG')
        ->and($market->getLanguages())->toBe(['en_SG'])
        ->and(LalamoveMarket::codes()->contains('SG'))->toBeTrue()
        ->and($serviceType)->toBeInstanceOf(LalamoveServiceType::class)
        ->and($serviceType->getKey())->toBe('MOTORCYCLE')
        ->and($serviceType->length)->toBe('40cm')
        ->and($stop->toArray())->toMatchArray([
            'coordinates' => ['lat' => '1.25', 'lng' => '103.75'],
            'address'     => 'Depot',
        ])
        ->and(json_decode($stop->toJson(), true))->toMatchArray(['address' => 'Depot'])
        ->and(Lalamove::asCents('12.34'))->toBe(1234);
});

test('lalamove creates service quote models and line items from quotation payloads', function () {
    session(['company' => 'company-uuid']);
    $quotation = (object) [
        'quotationId'    => 'quotation-1',
        'priceBreakdown' => (object) [
            'currency'     => 'SGD',
            'total'        => '12.34',
            'base'         => '10.00',
            'extraMileage' => '1.23',
            'vat'          => '1.11',
        ],
    ];

    $wrapped = (object) ['data' => $quotation];
    $quote   = Lalamove::serviceQuoteFromQuotation($wrapped, 'request-1');

    expect($quote)->toBeInstanceOf(ServiceQuote::class)
        ->and($quote->request_id)->toBe('request-1')
        ->and($quote->company_uuid)->toBe('company-uuid')
        ->and($quote->amount)->toBe('1234')
        ->and($quote->currency)->toBe('SGD')
        ->and($quote->items)->toHaveCount(3)
        ->and($quote->items[0]->amount)->toBe('1000')
        ->and($quote->items[0]->details)->toBe('Base fee')
        ->and($quote->items[1]->code)->toBe('EXTRA_MILEAGE_FEE');
})->skip('Standalone package model construction recurses through the container without the full app harness.');

test('lalamove signs and sends quotation and order requests through guzzle', function () {
    $history  = [];
    $lalamove = fleetOpsLalamoveWithResponses([
        new Response(200, [], json_encode(['data' => ['quotationId' => 'quotation-1']])),
        new Response(200, [], json_encode(['data' => ['orderId' => 'order-1']])),
    ], $history);

    $quotation = $lalamove->getQuotations(
        'MOTORCYCLE',
        [1.25],
        '2026-07-24 10:00:00',
        ['HELP_BUY'],
        true,
        ['quantity' => '1'],
        ['amount'   => '10.00']
    );
    $order = $lalamove->createOrder('quotation-1', ['name' => 'Sender', 'phone' => '+18004444444'], [
        ['stopId' => 'dropoff', 'name' => 'Recipient', 'phone' => '+18005555555'],
    ], true, true, ['fleetbase_order' => 'order_public']);

    expect($quotation->data->quotationId)->toBe('quotation-1')
        ->and($order->orderId)->toBe('order-1')
        ->and($history)->toHaveCount(2);

    $quotationRequest = $history[0]['request'];
    $quotationBody    = json_decode((string) $quotationRequest->getBody(), true);

    expect($quotationRequest->getMethod())->toBe('POST')
        ->and((string) $quotationRequest->getUri())->toContain('/v3/quotations')
        ->and($quotationRequest->getHeaderLine('Authorization'))->toStartWith('hmac api-key:')
        ->and($quotationRequest->getHeaderLine('Market'))->toBe('SG')
        ->and($quotationBody['data'])->toMatchArray([
            'serviceType'      => 'MOTORCYCLE',
            'language'         => 'en_SG',
            'isRouteOptimized' => true,
            'specialRequests'  => ['HELP_BUY'],
            'item'             => ['quantity' => '1'],
            'cashOnDelivery'   => ['amount' => '10.00'],
        ]);
    expect($quotationBody['data']['scheduleAt'])->toContain('2026-07-24');

    $orderBody = json_decode((string) $history[1]['request']->getBody(), true);
    expect($orderBody['data'])->toMatchArray([
        'quotationId'           => 'quotation-1',
        'sender'                => ['name' => 'Sender', 'phone' => '+18004444444'],
        'isRecipientSMSEnabled' => true,
        'isPODEnabled'          => true,
        'metadata'              => ['fleetbase_order' => 'order_public'],
    ]);
});

test('lalamove builds signatures urls options and throws vendor errors', function () {
    $history  = [];
    $lalamove = fleetOpsLalamoveWithResponses([
        new Response(200, [], json_encode([
            'errors' => [
                ['id' => 'ERR_1', 'message' => 'Invalid order', 'detail' => 'Order missing'],
            ],
        ])),
    ], $history);

    $signature = fleetOpsLalamoveInvoke($lalamove, 'createSignature', [
        '123456789',
        'post',
        'orders',
        '{"data":[]}',
    ]);

    expect($signature)->toBe(hash_hmac('sha256', "123456789\r\nPOST\r\n/v3/orders\r\n\r\n{\"data\":[]}", 'api-secret'))
        ->and(fleetOpsLalamoveInvoke($lalamove, 'buildRequestUrl', ['orders']))->toBe('https://rest.sandbox.lalamove.com/v3/orders')
        ->and($lalamove->setRequestId('request-1'))->toBe($lalamove)
        ->and($lalamove->setOptions(['priority' => true])->setOptions(['mode' => 'test'])->getOptions())->toBe([
            'priority' => true,
            'mode'     => 'test',
        ]);

    expect(fn () => $lalamove->cancelOrder('order-1'))
        ->toThrow(IntegratedVendorException::class, 'Lalamove: ERR_1 Invalid order (Order missing)');
});
