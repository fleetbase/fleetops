<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FleetOpsApiServiceQuoteControllerProbe extends ServiceQuoteController
{
    public ?ServiceQuote $serviceQuote                   = null;
    public ?FleetOpsApiServiceQuoteRateFake $serviceRate = null;
    public array $serviceRates                           = [];
    public array $createdQuotes                          = [];
    public array $createdItems                           = [];
    public array $placeLookups                           = [];
    public array $distanceMatrixCalls                    = [];
    public string $requestId                             = 'request_public';
    public bool $serviceQuoteNotFound                    = false;
    public array $findCalls                              = [];

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

    protected function serviceQuoteResourceCollection(iterable $serviceQuotes)
    {
        return ['collection' => 'service-quotes', 'items' => collect($serviceQuotes)->values()->all()];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function generateServiceQuoteRequestId(): string
    {
        return $this->requestId;
    }

    protected function findPlaceByPublicId(string $publicId): ?Place
    {
        $this->placeLookups[] = $publicId;

        return tap(new Place(), fn (Place $place) => $place->setRawAttributes([
            'uuid'      => $publicId . '-uuid',
            'public_id' => $publicId,
        ], true));
    }

    protected function distanceMatrix(array $origins, iterable $destinations): mixed
    {
        $this->distanceMatrixCalls[] = [$origins, collect($destinations)->values()->all()];

        return (object) ['distance' => 4200, 'time' => 900];
    }

    protected function findServiceRateByUuid(string $service): ?ServiceRate
    {
        return $this->serviceRate;
    }

    protected function getServicableServiceRates(iterable $waypoints, ?string $serviceType, mixed $currency, callable $callback): iterable
    {
        return $this->serviceRates;
    }

    protected function createServiceQuote(array $attributes): ServiceQuote
    {
        $quote = new FleetOpsApiServiceQuoteFake();
        $quote->setRawAttributes(array_merge(['uuid' => 'service-quote-' . (count($this->createdQuotes) + 1)], $attributes), true);
        $this->createdQuotes[] = $attributes;

        return $quote;
    }

    protected function createServiceQuoteItem(array $attributes): ServiceQuoteItem
    {
        $item = new ServiceQuoteItem();
        $item->setRawAttributes($attributes, true);
        $this->createdItems[] = $attributes;

        return $item;
    }
}

class FleetOpsApiServiceQuoteFake extends ServiceQuote
{
    public array $metaUpdates = [];

    public function updateMeta($key, $value = null): bool
    {
        $this->metaUpdates[$key] = $value;

        return true;
    }
}

class FleetOpsApiServiceQuoteRateFake extends ServiceRate
{
    public function __construct(
        string $uuid = 'service-rate-uuid',
        public int $amount = 1000,
        public string $currencyCode = 'USD',
    ) {
        parent::__construct();

        $this->setRawAttributes([
            'uuid'         => $uuid,
            'public_id'    => $uuid . '_public',
            'company_uuid' => 'company-uuid',
            'currency'     => $currencyCode,
        ], true);
    }

    public function quoteFromPreliminaryData($entities = [], $waypoints = [], ?int $totalDistance = 0, ?int $totalTime = 0, ?bool $isCashOnDelivery = false, ?int $endpointCount = null)
    {
        return [$this->amount, collect([[
            'amount'   => $this->amount,
            'currency' => $this->currencyCode,
            'details'  => [
                'distance'       => $totalDistance,
                'time'           => $totalTime,
                'cod'            => $isCashOnDelivery,
                'endpoint_count' => $endpointCount,
            ],
            'code' => 'base_fee',
        ]])];
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

test('api service quote controller creates preliminary quotes for a single service', function () {
    $controller              = new FleetOpsApiServiceQuoteControllerProbe();
    $controller->serviceRate = new FleetOpsApiServiceQuoteRateFake('service-rate-uuid', 1500, 'USD');

    $response = $controller->queryFromPreliminary(QueryServiceQuotesRequest::create('/fleetops/service-quotes', 'POST', [
        'pickup'       => 'place_pickup',
        'dropoff'      => 'place_dropoff',
        'service'      => 'service-rate-uuid',
        'distance'     => 1200,
        'time'         => 300,
        'single'       => true,
        'cod'          => true,
        'currency'     => 'USD',
        'service_type' => 'delivery',
    ]));

    $quote = $response['serviceQuote'];

    expect($response)->toMatchArray(['resource' => 'service-quote'])
        ->and($quote)->toBeInstanceOf(FleetOpsApiServiceQuoteFake::class)
        ->and($controller->placeLookups)->toBe(['place_dropoff'])
        ->and($controller->createdQuotes)->toBe([[
            'request_id'        => 'request_public',
            'company_uuid'      => 'company-uuid',
            'service_rate_uuid' => 'service-rate-uuid',
            'amount'            => 1500,
            'currency'          => 'USD',
        ]])
        ->and($controller->createdItems[0]['details'])->toBe([
            'distance'       => 1200,
            'time'           => 300,
            'cod'            => true,
            'endpoint_count' => 1,
        ])
        ->and($quote->metaUpdates['preliminary_data'])->toMatchArray([
            'pickup'   => 'place_pickup',
            'dropoff'  => 'place_dropoff',
            'cod'      => true,
            'currency' => true,
        ]);
});

test('api service quote controller creates preliminary quote collections from serviceable rates', function () {
    $controller               = new FleetOpsApiServiceQuoteControllerProbe();
    $controller->serviceRates = [
        new FleetOpsApiServiceQuoteRateFake('service-rate-a', 2500, 'USD'),
        new FleetOpsApiServiceQuoteRateFake('service-rate-b', 900, 'USD'),
    ];

    $response = $controller->queryFromPreliminary(QueryServiceQuotesRequest::create('/fleetops/service-quotes', 'POST', [
        'pickup'    => 'place_pickup',
        'dropoff'   => 'place_dropoff',
        'waypoints' => [['public_id' => 'place_middle']],
        'currency'  => 'USD',
    ]));

    expect($response['collection'])->toBe('service-quotes')
        ->and($response['items'])->toHaveCount(2)
        ->and($controller->distanceMatrixCalls)->toHaveCount(1)
        ->and($controller->createdQuotes)->toHaveCount(2)
        ->and($controller->createdQuotes[0]['amount'])->toBe(2500)
        ->and($controller->createdQuotes[1]['amount'])->toBe(900)
        ->and($controller->createdItems[0]['details'])->toMatchArray([
            'distance' => 4200,
            'time'     => 900,
        ]);
});
