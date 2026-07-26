<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PaymentController;

class FleetOpsPaymentControllerProbe extends PaymentController
{
    public mixed $company;
    public mixed $stripe;

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PaymentController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function getCompany(): mixed
    {
        return $this->company ?? null;
    }

    protected function stripeClient(): mixed
    {
        return $this->stripe;
    }

    protected function jsonResponse(array $payload)
    {
        return ['json' => $payload];
    }

    protected function errorResponse(string $message)
    {
        return ['error' => $message];
    }
}

class FleetOpsPaymentCompanyFake
{
    public array $updates = [];

    public function __construct(public ?string $stripe_connect_id = null)
    {
    }

    public function update(array $attributes): bool
    {
        $this->updates[] = $attributes;
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }

        return true;
    }
}

class FleetOpsPaymentStripeFake
{
    public object $accounts;
    public object $accountSessions;

    public function __construct(?Throwable $accountError = null, ?Throwable $sessionError = null)
    {
        $this->accounts = new class($accountError) {
            public array $creates = [];

            public function __construct(private ?Throwable $error)
            {
            }

            public function create(array $payload): object
            {
                if ($this->error) {
                    throw $this->error;
                }

                $this->creates[] = $payload;

                return (object) ['id' => 'acct_created'];
            }
        };

        $this->accountSessions = new class($sessionError) {
            public array $creates = [];

            public function __construct(private ?Throwable $error)
            {
            }

            public function create(array $payload): object
            {
                if ($this->error) {
                    throw $this->error;
                }

                $this->creates[] = $payload;

                return (object) ['client_secret' => 'session_secret'];
            }
        };
    }
}

test('payment controller recognizes Stripe Connect account ids', function (?string $accountId, bool $expected) {
    $controller = new FleetOpsPaymentControllerProbe();

    expect($controller->callHelper('hasStripeConnectId', $accountId))->toBe($expected);
})->with([
    'valid connect id' => ['acct_123', true],
    'empty id'         => ['', false],
    'null id'          => [null, false],
    'customer id'      => ['cus_123', false],
]);

test('payment controller totals received payments by service quote currency', function () {
    $controller = new FleetOpsPaymentControllerProbe();
    $payments   = [
        (object) ['serviceQuote' => (object) ['currency' => 'USD', 'amount' => 100]],
        (object) ['serviceQuote' => (object) ['currency' => 'USD', 'amount' => 25]],
        (object) ['serviceQuote' => (object) ['currency' => 'SGD', 'amount' => 50]],
    ];

    expect($controller->callHelper('totalsByServiceQuoteCurrency', $payments))->toBe([
        'USD' => 125,
        'SGD' => 50,
    ]);
});

test('payment controller reports Stripe Connect account status from the current company', function (?string $accountId, bool $expected) {
    $controller          = new FleetOpsPaymentControllerProbe();
    $controller->company = $accountId === '__missing__' ? null : new FleetOpsPaymentCompanyFake($accountId);

    expect($controller->hasStripeConnectAccount())->toBe([
        'json' => ['hasStripeConnectAccount' => $expected],
    ]);
})->with([
    'valid account'    => ['acct_123', true],
    'customer account' => ['cus_123', false],
    'missing company'  => ['__missing__', false],
]);

test('payment controller creates Stripe accounts and stores the account id', function () {
    $company             = new FleetOpsPaymentCompanyFake();
    $controller          = new FleetOpsPaymentControllerProbe();
    $controller->company = $company;
    $controller->stripe  = new FleetOpsPaymentStripeFake();

    expect($controller->getStripeAccount())->toBe([
        'json' => ['account' => 'acct_created'],
    ])
        ->and($company->updates)->toBe([
            ['stripe_connect_id' => 'acct_created'],
        ])
        ->and($controller->stripe->accounts->creates[0]['controller']['stripe_dashboard']['type'])->toBe('express')
        ->and($controller->stripe->accounts->creates[0]['controller']['fees']['payer'])->toBe('application')
        ->and($controller->stripe->accounts->creates[0]['controller']['losses']['payments'])->toBe('application');
});

test('payment controller returns Stripe account creation errors', function () {
    $controller         = new FleetOpsPaymentControllerProbe();
    $controller->stripe = new FleetOpsPaymentStripeFake(new Exception('stripe account failed'));

    expect($controller->getStripeAccount())->toBe([
        'error' => 'stripe account failed',
    ]);
});

test('payment controller creates Stripe account sessions with request or company accounts', function (?string $requestAccount, string $expectedAccount) {
    $controller          = new FleetOpsPaymentControllerProbe();
    $controller->company = new FleetOpsPaymentCompanyFake('acct_company');
    $controller->stripe  = new FleetOpsPaymentStripeFake();
    $request             = new Illuminate\Http\Request(array_filter(['account' => $requestAccount]));

    expect($controller->getStripeAccountSession($request))->toBe([
        'json' => ['clientSecret' => 'session_secret'],
    ])
        ->and($controller->stripe->accountSessions->creates[0])->toBe([
            'account'    => $expectedAccount,
            'components' => [
                'account_onboarding' => [
                    'enabled' => true,
                ],
            ],
        ]);
})->with([
    'request account' => ['acct_request', 'acct_request'],
    'company account' => [null, 'acct_company'],
]);

test('payment controller returns Stripe account session errors', function () {
    $controller          = new FleetOpsPaymentControllerProbe();
    $controller->company = new FleetOpsPaymentCompanyFake('acct_company');
    $controller->stripe  = new FleetOpsPaymentStripeFake(null, new Exception('stripe session failed'));

    expect($controller->getStripeAccountSession(new Illuminate\Http\Request()))->toBe([
        'error' => 'stripe session failed',
    ]);
});
