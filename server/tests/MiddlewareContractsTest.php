<?php

use Fleetbase\FleetOps\Http\Middleware\AuthenticateCustomerToken;
use Fleetbase\FleetOps\Http\Middleware\SetupDriverSession;
use Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Middleware\response')) {
    eval('namespace Fleetbase\FleetOps\Http\Middleware; function response() { return new class {
        public function apiError(string $message, int $status = 400) {
            return new \Illuminate\Http\JsonResponse(["error" => $message], $status);
        }
    }; }');
}

class FleetOpsSetupDriverSessionProbe extends SetupDriverSession
{
    public array $stored = [];

    protected function storeDriverInSession(string $driverUuid): void
    {
        $this->stored[] = $driverUuid;
    }
}

class FleetOpsSetupDriverSessionUserFake extends User
{
    public ?Driver $driverSession = null;

    public function load($relations)
    {
        $this->setRelation('currentDriverSession', $this->driverSession);

        return $this;
    }
}

class FleetOpsAuthenticateCustomerTokenProbe extends AuthenticateCustomerToken
{
    public mixed $customer = null;
    public mixed $current  = null;

    protected function resolveCustomerFromHeader(Request $request): mixed
    {
        return $this->customer;
    }

    protected function setCurrentCustomer(mixed $customer): void
    {
        $this->current = $customer;
    }
}

test('transform location middleware normalizes nested null locations', function () {
    $request = Request::create('/v1/orders', 'POST', [
        'location' => null,
        'payload'  => [
            'pickup'  => ['location' => null],
            'dropoff' => ['location' => [103.8, 1.3]],
            'meta'    => ['location' => null],
        ],
        'entities' => [
            ['name' => 'Box', 'location' => null],
            ['name' => 'Crate', 'location' => [104.1, 1.4]],
        ],
        'notes' => 'leave unchanged',
    ]);

    $response = (new TransformLocationMiddleware())->handle($request, function (Request $request) {
        return $request->all();
    });

    expect($response)->toMatchArray([
        'location' => [0, 0],
        'payload'  => [
            'pickup'  => ['location' => [0, 0]],
            'dropoff' => ['location' => [103.8, 1.3]],
            'meta'    => ['location' => [0, 0]],
        ],
        'entities' => [
            ['name' => 'Box', 'location' => [0, 0]],
            ['name' => 'Crate', 'location' => [104.1, 1.4]],
        ],
        'notes' => 'leave unchanged',
    ]);
});

test('authenticate customer token rejects missing or mismatched customers and continues valid requests', function () {
    $request    = Request::create('/v1/customers/orders', 'GET');
    $middleware = new FleetOpsAuthenticateCustomerTokenProbe();

    $missing = $middleware->handle($request, fn () => 'next-called');

    expect($missing->getStatusCode())->toBe(401)
        ->and($missing->getData(true))->toBe([
            'error' => 'Customer token is missing or invalid.',
        ]);

    session(['company' => 'session-company-uuid']);
    $middleware->customer = (object) ['company_uuid' => 'other-company-uuid'];

    $mismatched = $middleware->handle($request, fn () => 'next-called');

    expect($mismatched->getStatusCode())->toBe(403)
        ->and($mismatched->getData(true))->toBe([
            'error' => 'Customer does not belong to this company.',
        ])
        ->and($middleware->current)->toBeNull();

    $middleware->customer = (object) ['company_uuid' => 'session-company-uuid'];
    $valid                = $middleware->handle($request, fn ($request) => ['continued' => $request->path()]);

    expect($valid)->toBe(['continued' => 'v1/customers/orders'])
        ->and($middleware->current)->toBe($middleware->customer);
});

test('setup driver session stores current driver session and continues request', function () {
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-uuid'], true);

    $user                = new FleetOpsSetupDriverSessionUserFake();
    $user->driverSession = $driver;

    $request = Request::create('/driver/session', 'GET');
    $request->setUserResolver(fn () => $user);

    $middleware = new FleetOpsSetupDriverSessionProbe();
    $response   = $middleware->handle($request, fn ($request) => 'next-called');

    expect($response)->toBe('next-called')
        ->and($middleware->stored)->toBe(['driver-uuid']);

    $anonymous = Request::create('/driver/session', 'GET');
    $anonymous->setUserResolver(fn () => null);

    $response = $middleware->handle($anonymous, fn ($request) => 'anonymous-next');

    expect($response)->toBe('anonymous-next')
        ->and($middleware->stored)->toBe(['driver-uuid']);
});
