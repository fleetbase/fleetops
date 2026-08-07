<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsInternalServiceQuoteCheckoutControllerProbe extends ServiceQuoteController
{
    public ?FleetOpsInternalServiceQuoteCheckoutQuoteFake $serviceQuote        = null;
    public ?FleetOpsInternalServiceQuoteCheckoutPurchaseRateFake $purchaseRate = null;
    public mixed $stripe;
    public bool $purchaseExists                                       = false;
    public array $jsonResponses                                       = [];
    public array $errors                                              = [];
    public array $checkoutUris                                        = [];
    public ?Throwable $checkoutError                                  = null;
    public ?Payload $payload                                          = null;
    public ?FleetOpsInternalServiceQuoteCheckoutRateFake $serviceRate = null;
    public array $serviceRates                                        = [];
    public array $createdQuotes                                       = [];
    public array $createdItems                                        = [];
    public array $createdPlaces                                       = [];
    public array $distanceMatrixCalls                                 = [];
    public string $requestId                                          = 'request_public';

    protected function generateServiceQuoteRequestId(): string
    {
        return $this->requestId;
    }

    protected function findPayloadForQuote(string $payload): ?Payload
    {
        return $this->payload;
    }

    protected function findServiceRateForQuote(string $service, ?string $currency): ?ServiceRate
    {
        return $this->serviceRate;
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
        $quote = new FleetOpsInternalServiceQuoteCheckoutQuoteFake('service-quote-' . (count($this->createdQuotes) + 1));
        $quote->setRawAttributes(array_merge($quote->getAttributes(), $attributes), true);
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

    protected function createPlaceFromMixed(mixed $value): Place
    {
        $place = new Place();
        $place->setRawAttributes([
            'uuid'      => data_get($value, 'uuid', 'place-' . (count($this->createdPlaces) + 1)),
            'public_id' => data_get($value, 'public_id'),
        ], true);
        $this->createdPlaces[] = $value;

        return $place;
    }

    protected function distanceMatrix(array $origins, iterable $destinations): mixed
    {
        $this->distanceMatrixCalls[] = [$origins, collect($destinations)->values()->all()];

        return (object) ['distance' => 4200, 'time' => 900];
    }

    protected function findServiceQuoteForPurchase(?string $uuid): ?ServiceQuote
    {
        return $uuid === 'missing' ? null : ($this->serviceQuote ??= new FleetOpsInternalServiceQuoteCheckoutQuoteFake($uuid ?? 'service-quote-uuid'));
    }

    protected function purchaseRateExists(ServiceQuote $serviceQuote): bool
    {
        return $this->purchaseExists;
    }

    protected function createStripeCheckoutSessionForQuote(ServiceQuote $serviceQuote, string $redirectUri): mixed
    {
        if ($this->checkoutError) {
            throw $this->checkoutError;
        }

        $this->checkoutUris[] = [$serviceQuote->uuid, $redirectUri];

        return (object) ['client_secret' => 'checkout_secret'];
    }

    protected function firstOrCreatePurchaseRate(ServiceQuote $serviceQuote): PurchaseRate
    {
        return $this->purchaseRate ??= new FleetOpsInternalServiceQuoteCheckoutPurchaseRateFake('purchase-rate-uuid');
    }

    protected function stripeClient(): mixed
    {
        return $this->stripe;
    }

    protected function jsonResponse(mixed $payload, int $status = 200)
    {
        $this->jsonResponses[] = [$payload, $status];

        return ['json' => $payload, 'status' => $status];
    }

    protected function errorResponse(string $message)
    {
        $this->errors[] = $message;

        return ['error' => $message];
    }
}

class FleetOpsInternalServiceQuoteCheckoutQuoteFake extends ServiceQuote
{
    public int $flushes       = 0;
    public array $metaUpdates = [];

    public function __construct(string $uuid = 'service-quote-uuid')
    {
        parent::__construct();

        $this->setRawAttributes(['uuid' => $uuid], true);
    }

    public function flushCache(): void
    {
        $this->flushes++;
    }

    public function updateMeta($key, $value = null): bool
    {
        $this->metaUpdates[$key] = $value;

        return true;
    }
}

class FleetOpsInternalServiceQuoteCheckoutPayloadFake extends Payload
{
    public function getAllStops(): Collection
    {
        return collect([
            ['uuid' => 'pickup-uuid'],
            ['uuid' => 'dropoff-uuid'],
        ]);
    }
}

class FleetOpsInternalServiceQuoteCheckoutRateFake extends ServiceRate
{
    public function __construct(
        string $uuid = 'service-rate-uuid',
        public int $amount = 1000,
        public string $currencyCode = 'USD',
    ) {
        parent::__construct();

        $this->setRawAttributes([
            'uuid'         => $uuid,
            'company_uuid' => 'company-uuid',
            'currency'     => $currencyCode,
        ], true);
    }

    public function quote($payload)
    {
        return [$this->amount, collect([[
            'amount'   => $this->amount,
            'currency' => $this->currencyCode,
            'details'  => ['payload' => $payload instanceof Payload],
            'code'     => 'payload_fee',
        ]])];
    }

    public function quoteFromPreliminaryData($entities = [], $waypoints = [], ?int $totalDistance = 0, ?int $totalTime = 0, ?bool $isCashOnDelivery = false, ?int $endpointCount = null)
    {
        return [$this->amount, collect([[
            'amount'   => $this->amount,
            'currency' => $this->currencyCode,
            'details'  => [
                'entities'       => collect($entities)->count(),
                'waypoints'      => collect($waypoints)->count(),
                'distance'       => $totalDistance,
                'time'           => $totalTime,
                'cod'            => $isCashOnDelivery,
                'endpoint_count' => $endpointCount,
            ],
            'code' => 'preliminary_fee',
        ]])];
    }
}

class FleetOpsInternalServiceQuoteCheckoutPurchaseRateFake extends PurchaseRate
{
    public array $metaUpdates = [];

    public function __construct(string $uuid = 'purchase-rate-uuid')
    {
        parent::__construct();

        $this->setRawAttributes(['uuid' => $uuid], true);
    }

    public function updateMetaProperties(array $properties = []): bool
    {
        $this->metaUpdates[] = $properties;

        return true;
    }
}

class FleetOpsInternalServiceQuoteCheckoutStripeFake
{
    public object $checkout;

    public function __construct(public object $session)
    {
        $this->checkout = new class($this->session) {
            public object $sessions;

            public function __construct(object $session)
            {
                $this->sessions = new class($session) {
                    public array $retrieves = [];

                    public function __construct(private object $session)
                    {
                    }

                    public function retrieve(string $sessionId): object
                    {
                        $this->retrieves[] = $sessionId;

                        if ($this->session instanceof Throwable) {
                            throw $this->session;
                        }

                        return $this->session;
                    }
                };
            }
        };
    }
}

test('internal service quote query record creates single service quotes from payloads', function () {
    $controller              = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->payload     = new FleetOpsInternalServiceQuoteCheckoutPayloadFake();
    $controller->serviceRate = new FleetOpsInternalServiceQuoteCheckoutRateFake('service-rate-uuid', 1750, 'USD');

    $response = $controller->queryRecord(new Request([
        'payload'       => 'payload_public',
        'service'       => 'service-rate-uuid',
        'single'        => true,
        'currency'      => 'USD',
        'service_type'  => 'delivery',
    ]));

    $quote = $response['json'];

    expect($response['status'])->toBe(200)
        ->and($quote)->toBeInstanceOf(FleetOpsInternalServiceQuoteCheckoutQuoteFake::class)
        ->and($quote->getRelation('serviceRate'))->toBe($controller->serviceRate)
        ->and($quote->getRelation('items'))->toHaveCount(1)
        ->and($controller->createdQuotes)->toBe([[
            'request_id'        => 'request_public',
            'company_uuid'      => 'company-uuid',
            'service_rate_uuid' => 'service-rate-uuid',
            'amount'            => 1750,
            'currency'          => 'USD',
        ]])
        ->and($controller->createdItems)->toBe([[
            'service_quote_uuid' => 'service-quote-1',
            'amount'             => 1750,
            'currency'           => 'USD',
            'details'            => ['payload' => true],
            'code'               => 'payload_fee',
        ]]);
});

test('internal service quote preliminary query creates quote collections from serviceable rates', function () {
    $controller               = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->serviceRates = [
        new FleetOpsInternalServiceQuoteCheckoutRateFake('service-rate-a', 2500, 'USD'),
        new FleetOpsInternalServiceQuoteCheckoutRateFake('service-rate-b', 900, 'USD'),
    ];

    $response = $controller->preliminaryQuery(new Request([
        'payload'      => [
            'pickup'    => ['uuid' => 'pickup-uuid', 'public_id' => 'place_pickup'],
            'dropoff'   => ['uuid' => 'dropoff-uuid', 'public_id' => 'place_dropoff'],
            'waypoints' => [['uuid' => 'waypoint-uuid']],
            'entities'  => [['uuid' => 'entity-uuid']],
        ],
        'cod'          => true,
        'currency'     => 'USD',
        'service_type' => 'delivery',
    ]));

    $quotes = $response['json'];

    expect($response['status'])->toBe(200)
        ->and($quotes)->toBeInstanceOf(Collection::class)
        ->and($quotes)->toHaveCount(2)
        ->and($controller->createdPlaces)->toHaveCount(2)
        ->and($controller->distanceMatrixCalls)->toHaveCount(1)
        ->and($controller->createdQuotes)->toHaveCount(2)
        ->and($controller->createdQuotes[0]['amount'])->toBe(2500)
        ->and($controller->createdQuotes[1]['amount'])->toBe(900)
        ->and($controller->createdItems[0]['details'])->toMatchArray([
            'entities'       => 1,
            'waypoints'      => 3,
            'distance'       => 4200,
            'time'           => 900,
            'cod'            => true,
            'endpoint_count' => 2,
        ])
        ->and($quotes->first()->metaUpdates['preliminary_query'])->toMatchArray([
            'payload'      => [
                'pickup'    => ['uuid' => 'pickup-uuid', 'public_id' => 'place_pickup'],
                'dropoff'   => ['uuid' => 'dropoff-uuid', 'public_id' => 'place_dropoff'],
                'waypoints' => [['uuid' => 'waypoint-uuid']],
                'entities'  => [['uuid' => 'entity-uuid']],
            ],
            'service_type' => 'delivery',
            'cod'          => true,
            'currency'     => 'USD',
        ]);
});

test('internal service quote checkout session creation handles missing success and errors', function () {
    $controller = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();

    expect($controller->createStripeCheckoutSession(new Request([
        'service_quote' => 'missing',
        'uri'           => 'https://fleetbase.test/return',
    ])))->toBe([
        'error' => 'The service quote to purchase does not exist.',
    ]);

    $quote                    = new FleetOpsInternalServiceQuoteCheckoutQuoteFake('service-quote-uuid');
    $controller               = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->serviceQuote = $quote;

    expect($controller->createStripeCheckoutSession(new Request([
        'service_quote' => 'service-quote-uuid',
        'uri'           => 'https://fleetbase.test/return',
    ])))->toBe([
        'json'   => ['clientSecret' => 'checkout_secret'],
        'status' => 200,
    ])->and($controller->checkoutUris)->toBe([['service-quote-uuid', 'https://fleetbase.test/return']]);

    $controller->checkoutError = new RuntimeException('checkout failed');

    expect($controller->createStripeCheckoutSession(new Request([
        'service_quote' => 'service-quote-uuid',
        'uri'           => 'https://fleetbase.test/return',
    ])))->toBe([
        'error' => 'checkout failed',
    ]);
});

test('internal service quote checkout status reports purchases and Stripe sessions', function () {
    $controller                 = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->serviceQuote   = new FleetOpsInternalServiceQuoteCheckoutQuoteFake('service-quote-uuid');
    $controller->purchaseExists = true;

    expect($controller->getStripeCheckoutSessionStatus(new Request([
        'service_quote'        => 'service-quote-uuid',
        'checkout_session_id'  => 'cs_existing',
    ])))->toBe([
        'json' => [
            'status'        => 'purchase_complete',
            'service_quote' => $controller->serviceQuote,
        ],
        'status' => 200,
    ])->and($controller->serviceQuote->flushes)->toBe(1);

    $session = (object) [
        'id'             => 'cs_complete',
        'status'         => 'complete',
        'payment_intent' => 'pi_complete',
        'amount_total'   => 1250,
    ];
    $controller               = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->serviceQuote = new FleetOpsInternalServiceQuoteCheckoutQuoteFake('service-quote-uuid');
    $controller->stripe       = new FleetOpsInternalServiceQuoteCheckoutStripeFake($session);

    expect($controller->getStripeCheckoutSessionStatus(new Request([
        'service_quote'       => 'service-quote-uuid',
        'checkout_session_id' => 'cs_complete',
    ])))->toBe([
        'json' => [
            'status'       => 'complete',
            'serviceQuote' => $controller->serviceQuote,
            'purchaseRate' => $controller->purchaseRate,
        ],
        'status' => 200,
    ])->and($controller->purchaseRate->metaUpdates)->toBe([[
        'stripe_checkout_session_id' => 'cs_complete',
        'stripe_payment_intent_id'   => 'pi_complete',
        'locked_price'               => 1250,
    ]])->and($controller->serviceQuote->flushes)->toBe(2);

    $openSession              = (object) ['id' => 'cs_open', 'status' => 'open'];
    $controller               = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->serviceQuote = new FleetOpsInternalServiceQuoteCheckoutQuoteFake('service-quote-uuid');
    $controller->stripe       = new FleetOpsInternalServiceQuoteCheckoutStripeFake($openSession);

    expect($controller->getStripeCheckoutSessionStatus(new Request([
        'service_quote'       => 'service-quote-uuid',
        'checkout_session_id' => 'cs_open',
    ])))->toBe([
        'json' => [
            'status'       => 'open',
            'serviceQuote' => $controller->serviceQuote,
            'purchaseRate' => null,
        ],
        'status' => 200,
    ]);
});

test('internal service quote checkout status handles missing quotes and Stripe errors', function () {
    $controller = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();

    expect($controller->getStripeCheckoutSessionStatus(new Request([
        'service_quote'       => 'missing',
        'checkout_session_id' => 'cs_missing',
    ])))->toBe([
        'error' => 'The service quote to purchase does not exist.',
    ]);

    $controller               = new FleetOpsInternalServiceQuoteCheckoutControllerProbe();
    $controller->serviceQuote = new FleetOpsInternalServiceQuoteCheckoutQuoteFake('service-quote-uuid');
    $controller->stripe       = new FleetOpsInternalServiceQuoteCheckoutStripeFake(new Error('stripe failed'));

    expect($controller->getStripeCheckoutSessionStatus(new Request([
        'service_quote'       => 'service-quote-uuid',
        'checkout_session_id' => 'cs_error',
    ])))->toBe([
        'error' => 'stripe failed',
    ]);
});
