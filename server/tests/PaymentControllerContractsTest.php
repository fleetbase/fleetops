<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PaymentController;

class FleetOpsPaymentControllerProbe extends PaymentController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PaymentController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
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
