<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FleetOpsApiServiceQuoteControllerProbe extends ServiceQuoteController
{
    public ?ServiceQuote $serviceQuote = null;
    public bool $serviceQuoteNotFound  = false;
    public array $findCalls            = [];

    protected function findServiceQuote(string $id): ServiceQuote
    {
        $this->findCalls[] = $id;

        if ($this->serviceQuoteNotFound) {
            throw new ModelNotFoundException();
        }

        $this->serviceQuote ??= new ServiceQuote();
        $this->serviceQuote->setRawAttributes([
            'uuid'      => 'service-quote-uuid',
            'public_id' => 'service_quote_public',
            'amount'    => 1200,
            'currency'  => 'USD',
        ], true);

        return $this->serviceQuote;
    }

    protected function serviceQuoteResource(ServiceQuote $serviceQuote)
    {
        return ['resource' => 'service-quote', 'serviceQuote' => $serviceQuote];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

test('api service quote controller finds service quotes and reports missing resources', function () {
    $quote = new ServiceQuote();
    $quote->setRawAttributes([
        'uuid'      => 'service-quote-uuid',
        'public_id' => 'service_quote_public',
        'amount'    => 1200,
        'currency'  => 'USD',
    ], true);

    $controller               = new FleetOpsApiServiceQuoteControllerProbe();
    $controller->serviceQuote = $quote;

    expect($controller->find('service_quote_public'))->toBe([
        'resource'     => 'service-quote',
        'serviceQuote' => $quote,
    ])
        ->and($controller->findCalls)->toBe(['service_quote_public']);

    $controller                       = new FleetOpsApiServiceQuoteControllerProbe();
    $controller->serviceQuoteNotFound = true;

    expect($controller->find('missing-service-quote'))->toBe([
        'json'   => ['error' => 'ServiceQuote resource not found.'],
        'status' => 404,
    ]);
});
