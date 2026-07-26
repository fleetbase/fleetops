<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Http\Request;

class FleetOpsInternalServiceQuoteCheckoutControllerProbe extends ServiceQuoteController
{
    public ?FleetOpsInternalServiceQuoteCheckoutQuoteFake $serviceQuote        = null;
    public ?FleetOpsInternalServiceQuoteCheckoutPurchaseRateFake $purchaseRate = null;
    public mixed $stripe;
    public bool $purchaseExists      = false;
    public array $jsonResponses      = [];
    public array $errors             = [];
    public array $checkoutUris       = [];
    public ?Throwable $checkoutError = null;

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

    protected function jsonResponse(array $payload)
    {
        $this->jsonResponses[] = $payload;

        return ['json' => $payload];
    }

    protected function errorResponse(string $message)
    {
        $this->errors[] = $message;

        return ['error' => $message];
    }
}

class FleetOpsInternalServiceQuoteCheckoutQuoteFake extends ServiceQuote
{
    public int $flushes = 0;

    public function __construct(string $uuid = 'service-quote-uuid')
    {
        parent::__construct();

        $this->setRawAttributes(['uuid' => $uuid], true);
    }

    public function flushCache(): void
    {
        $this->flushes++;
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
        'json' => ['clientSecret' => 'checkout_secret'],
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
