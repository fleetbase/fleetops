<?php

use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Covers how the Lalamove client interprets API responses: an `errors` key
 * raises the integration exception, a `data` key is unwrapped, and anything
 * else is handed back untouched. The Guzzle client is swapped for a mock so
 * no request leaves the process.
 */
function fleetopsLalamoveWithResponses(array $responses): Lalamove
{
    $lalamove = new Lalamove('key-1', 'secret-1', true, 'SG');

    $stack  = HandlerStack::create(new MockHandler($responses));
    $client = new Client(['handler' => $stack, 'base_uri' => 'https://rest.sandbox.lalamove.com']);

    $reflection = new ReflectionProperty(Lalamove::class, 'client');
    $reflection->setAccessible(true);
    $reflection->setValue($lalamove, $client);

    return $lalamove;
}

test('order creation unwraps data and surfaces api errors', function () {
    // A `data` envelope is unwrapped for the caller
    $withData = fleetopsLalamoveWithResponses([
        new Response(200, [], json_encode(['data' => ['id' => 'lalamove-order-1', 'status' => 'ASSIGNING_DRIVER']])),
    ]);
    $created = $withData->createOrder('quotation-1', ['name' => 'Sender', 'phone' => '+6591234567'], []);
    expect($created->id)->toBe('lalamove-order-1');

    // A response carrying neither errors nor data is returned as-is
    $bare = fleetopsLalamoveWithResponses([
        new Response(200, [], json_encode(['acknowledged' => true])),
    ]);
    expect($bare->createOrder('quotation-2', ['name' => 'Sender', 'phone' => '+6591234567'], [])->acknowledged)->toBeTrue();

    // An `errors` key raises the integration exception naming the method
    $withErrors = fleetopsLalamoveWithResponses([
        new Response(200, [], json_encode(['errors' => [['id' => 'ERR_INVALID_QUOTATION', 'message' => 'Quotation expired']]])),
    ]);
    expect(fn () => $withErrors->createOrder('quotation-3', ['name' => 'Sender', 'phone' => '+6591234567'], []))
        ->toThrow(Fleetbase\FleetOps\Exceptions\IntegratedVendorException::class);
});

test('webhook registration returns bare responses untouched', function () {
    // setWebhook shares the same response handling as order creation
    $bare = fleetopsLalamoveWithResponses([
        new Response(200, [], json_encode(['acknowledged' => true])),
    ]);

    expect($bare->setWebhook('https://hooks.example.test/lalamove')->acknowledged)->toBeTrue();
});
