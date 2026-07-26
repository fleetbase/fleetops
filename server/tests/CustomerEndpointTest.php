<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Middleware\AuthenticateCustomerToken;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Customer as CustomerResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer as CustomerModel;
use Fleetbase\FleetOps\Support\CustomerAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\response')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function response() { return new class {
        public function apiError(string $message, int $status = 400): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse(["error" => $message], $status);
        }

        public function json(array $payload = [], int $status = 200): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse($payload, $status);
        }
    }; }');
}

function fleetopsCustomerControllerProtectedMethod(string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod(CustomerController::class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

function fleetopsCustomerEndpointJson(JsonResponse $response): array
{
    return $response->getData(true);
}

/*
|--------------------------------------------------------------------------
| Customer API surface — static shape checks.
|
| These mirror the lightweight static checks used elsewhere in this package
| (see PingDriverEndpointTest.php). They verify that the customer endpoints,
| controller methods, middleware, and supporting classes exist and are wired
| through `Api/v1/CustomerController` without the Storefront layer.
|
| End-to-end HTTP tests against a running app belong in the parent `api/`
| project's test harness; this file ensures the package surface is correct.
|--------------------------------------------------------------------------
*/

test('customers route group is registered inside the consumable v1 namespace', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)
        ->toContain("\$router->group(['prefix' => 'customers', 'middleware' => []]")
        ->and($routes)->toContain('CustomerController@requestCreationCode')
        ->and($routes)->toContain('CustomerController@create')
        ->and($routes)->toContain('CustomerController@login')
        ->and($routes)->toContain('CustomerController@loginWithPhone')
        ->and($routes)->toContain('CustomerController@verifyCode')
        ->and($routes)->toContain('CustomerController@forgotPassword')
        ->and($routes)->toContain('CustomerController@resetPassword');
});

test('authenticated customer routes are gated by AuthenticateCustomerToken middleware', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)
        ->toContain('AuthenticateCustomerToken::class')
        ->and($routes)->toContain('CustomerController@me')
        ->and($routes)->toContain('CustomerController@updateMe')
        ->and($routes)->toContain('CustomerController@logout')
        ->and($routes)->toContain('CustomerController@logoutAll')
        ->and($routes)->toContain('CustomerController@orders')
        ->and($routes)->toContain('CustomerController@createOrder')
        ->and($routes)->toContain('CustomerController@findOrder')
        ->and($routes)->toContain('CustomerController@places')
        ->and($routes)->toContain('CustomerController@registerDevice');
});

test('customer controller exposes the documented method surface', function () {
    $expected = [
        'requestCreationCode', 'create',
        'login', 'loginWithPhone', 'verifyCode',
        'forgotPassword', 'resetPassword',
        'me', 'updateMe', 'logout', 'logoutAll',
        'orders', 'createOrder', 'findOrder',
        'places', 'registerDevice',
    ];

    foreach ($expected as $method) {
        expect(method_exists(CustomerController::class, $method))->toBeTrue("CustomerController::{$method} missing");
    }
});

test('customer controller does not reference Storefront concerns', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/CustomerController.php');
    $source = preg_replace('/^\s*\/\/.*$/m', '', $source);

    expect($source)
        ->not->toContain('Storefront::about')
        ->not->toContain("session('storefront_key')")
        ->not->toContain("session('storefront_store')")
        ->not->toContain("session('storefront_network')")
        ->not->toContain('storefront_id')
        ->not->toContain('createStripeCustomerForContact');
});

test('customer controller does not introduce client-portal field aliases', function () {
    // Public Fleetbase APIs must accept canonical model field names only. No
    // aliasing like line1/state/zip → street1/province/postal_code, no portal-
    // specific tagging like meta.origin, no top-level item/weight/value/mode
    // aliases on order create — clients conform to the API, not vice versa.
    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/CustomerController.php');

    expect($source)
        ->not->toContain("'line1'")
        ->not->toContain("'line2'")
        ->not->toContain("'state'")
        ->not->toContain("'zip'")
        ->not->toContain("'origin' => 'fleetops_customer_portal'")
        ->not->toContain('fleetops_customer_portal')
        ->not->toContain("\$request->input('item'")
        ->not->toContain("\$request->input('value'")
        ->not->toContain("\$request->input('delivery'")
        ->not->toContain("\$request->input('category'");
});

test('createOrder mirrors the canonical Fleet-Ops order shape', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/CustomerController.php');

    expect($source)
        ->toContain('OrderConfig::resolveFromIdentifier')
        ->toContain('buildPayloadFromInput')
        ->toContain('Payload')
        ->toContain('setPickup')
        ->toContain('setDropoff')
        ->toContain('setEntities')
        ->toContain("'customer_uuid'");
});

test('verification code slugs are FleetOps-namespaced, not Storefront', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/CustomerController.php');

    expect($source)
        ->toContain("'fleetops_create_customer'")
        ->toContain("'fleetops_customer_login'")
        ->toContain("'fleetops_customer_password_reset'")
        ->not->toContain("'storefront_create_customer'")
        ->not->toContain("'storefront_login'");
});

test('AuthenticateCustomerToken enforces a customer-token + company cross-check', function () {
    expect(class_exists(AuthenticateCustomerToken::class))->toBeTrue();

    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Middleware/AuthenticateCustomerToken.php');
    expect($source)
        ->toContain('Customer token is missing or invalid')
        ->toContain('Customer does not belong to this company')
        ->toContain('CustomerAuth::resolveFromHeader')
        ->toContain('CustomerAuth::setCurrent');
});

test('CustomerAuth resolves tokens by contact UUID with company-preferred fallback', function () {
    expect(class_exists(CustomerAuth::class))->toBeTrue()
        ->and(method_exists(CustomerAuth::class, 'resolveFromHeader'))->toBeTrue()
        ->and(method_exists(CustomerAuth::class, 'current'))->toBeTrue()
        ->and(method_exists(CustomerAuth::class, 'setCurrent'))->toBeTrue()
        ->and(CustomerAuth::HEADER)->toBe('Customer-Token');

    $source = file_get_contents(dirname(__DIR__) . '/src/Support/CustomerAuth.php');
    expect($source)
        ->toContain('PersonalAccessToken::findToken')
        ->toContain("->where('type', 'customer')")
        ->toContain("->where('company_uuid'");
});

test('CustomerAuth returns null when no customer token or binding exists', function () {
    app()->forgetInstance(CustomerAuth::APP_BINDING);

    expect(CustomerAuth::resolveFromHeader(Request::create('/customers/me', 'GET')))->toBeNull()
        ->and(CustomerAuth::current())->toBeNull();
});

test('Customer model extends Contact with a type=customer global scope', function () {
    expect(is_subclass_of(CustomerModel::class, Contact::class))->toBeTrue();

    $source = file_get_contents(dirname(__DIR__) . '/src/Models/Customer.php');
    expect($source)
        ->toContain("\$model->type = 'customer'")
        ->toContain("->where('type', 'customer')");
});

test('Customer API resource exposes token and orders_count for the consumable shape', function () {
    expect(class_exists(CustomerResource::class))->toBeTrue();

    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Customer.php');
    expect($source)
        ->toContain("'token'")
        ->toContain("'orders_count'")
        ->toContain("Str::replaceFirst('contact', 'customer'");
});

test('Customer API resource includes a company sub-object resolved from the API credential', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/Customer.php');

    expect($source)
        // The sub-object lives on `company`, keyed off the customer's company_uuid.
        ->toContain("'company'")
        ->toContain('buildCompanyPayload')
        // Currency must be resolved through the existing canonical helper —
        // never hardcoded, never aliased.
        ->toContain('Utils::getCompanyTransactionCurrency')
        // Public-safe projection only: id, name, currency, country, phone.
        ->toContain("'currency'")
        ->toContain("'country'")
        ->toContain("'phone'");
});

test('FormRequest validators are present and authorize via api credential', function () {
    expect(class_exists(CreateCustomerRequest::class))->toBeTrue()
        ->and(class_exists(VerifyCreateCustomerRequest::class))->toBeTrue()
        ->and(class_exists(CreateCustomerOrderRequest::class))->toBeTrue();

    $create = file_get_contents(dirname(__DIR__) . '/src/Http/Requests/CreateCustomerRequest.php');
    $verify = file_get_contents(dirname(__DIR__) . '/src/Http/Requests/VerifyCreateCustomerRequest.php');

    expect($create)
        ->toContain("'code'     => 'required|exists:verification_codes,code'")
        ->toContain("'password' => 'required|string|min:8'")
        ->and($verify)->toContain("'mode'     => 'required|in:email,sms'");
});

test('customer controller rejects invalid public auth inputs before persistence', function () {
    $controller = new CustomerController();

    $invalidEmail = $controller->requestCreationCode(new VerifyCreateCustomerRequest([
        'mode'     => 'email',
        'identity' => '+15551234567',
    ]));
    $missingLogin = $controller->login(Request::create('/v1/customers/login', 'POST', [
        'identity' => 'jane@example.test',
    ]));
    $missingForgotPassword = $controller->forgotPassword(Request::create('/v1/customers/forgot-password', 'POST'));
    $missingResetPassword  = $controller->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
        'identity' => 'jane@example.test',
        'code'     => '123456',
    ]));
    $shortResetPassword = $controller->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
        'identity' => 'jane@example.test',
        'code'     => '123456',
        'password' => 'short',
    ]));

    expect($invalidEmail->getStatusCode())->toBe(400)
        ->and(fleetopsCustomerEndpointJson($invalidEmail))->toBe(['error' => 'Invalid email provided for identity.'])
        ->and($missingLogin->getStatusCode())->toBe(400)
        ->and(fleetopsCustomerEndpointJson($missingLogin))->toBe(['error' => 'Identity and password are required.'])
        ->and($missingForgotPassword->getStatusCode())->toBe(400)
        ->and(fleetopsCustomerEndpointJson($missingForgotPassword))->toBe(['error' => 'Identity is required.'])
        ->and($missingResetPassword->getStatusCode())->toBe(400)
        ->and(fleetopsCustomerEndpointJson($missingResetPassword))->toBe(['error' => 'identity, code, and password are required.'])
        ->and($shortResetPassword->getStatusCode())->toBe(400)
        ->and(fleetopsCustomerEndpointJson($shortResetPassword))->toBe(['error' => 'Password must be at least 8 characters.']);
});

test('customer controller authenticated endpoints require a current customer', function () {
    app()->forgetInstance(CustomerAuth::APP_BINDING);

    $controller = new CustomerController();
    $request    = Request::create('/v1/customers', 'GET');

    foreach ([
        $controller->me(),
        $controller->updateMe(new UpdateContactRequest()),
        $controller->logout($request),
        $controller->logoutAll(),
        $controller->orders($request),
        $controller->findOrder('order_123'),
        $controller->createOrder(new CreateCustomerOrderRequest()),
        $controller->places($request),
        $controller->registerDevice(Request::create('/v1/customers/devices', 'POST')),
    ] as $response) {
        expect($response->getStatusCode())->toBe(401)
            ->and(fleetopsCustomerEndpointJson($response))->toBe(['error' => 'Not authenticated.']);
    }
});

test('customer controller handles lightweight authenticated profile and logout flows', function () {
    $customer = new Contact();
    $customer->setRawAttributes([
        'uuid'         => 'customer-uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company-uuid',
        'type'         => 'customer',
        'user_uuid'    => null,
    ], true);

    app()->instance(CustomerAuth::APP_BINDING, $customer);

    $controller     = new CustomerController();
    $profile        = $controller->me();
    $logout         = $controller->logout(Request::create('/v1/customers/logout', 'POST'));
    $logoutAllError = $controller->logoutAll();

    expect($profile)->toBeInstanceOf(CustomerResource::class)
        ->and($profile->resource)->toBe($customer)
        ->and($logout->getStatusCode())->toBe(200)
        ->and(fleetopsCustomerEndpointJson($logout))->toBe(['status' => 'ok'])
        ->and($logoutAllError->getStatusCode())->toBe(401)
        ->and(fleetopsCustomerEndpointJson($logoutAllError))->toBe(['error' => 'Not authenticated.']);
});

test('customer controller helper methods normalize phones and ignore empty place inputs', function () {
    $controller = new CustomerController();
    $resolver   = fleetopsCustomerControllerProtectedMethod('resolveCustomerPlace');
    $customer   = new Contact();
    $customer->setRawAttributes(['uuid' => 'customer-uuid'], true);

    expect(CustomerController::phone(null))->toBe('')
        ->and(CustomerController::phone(''))->toBe('')
        ->and(CustomerController::phone(' 15551234567 '))->toBe('+15551234567')
        ->and(CustomerController::phone('+15551234567'))->toBe('+15551234567')
        ->and($resolver->invoke($controller, null, $customer, 'company-uuid'))->toBeNull()
        ->and($resolver->invoke($controller, [], $customer, 'company-uuid'))->toBeNull()
        ->and($resolver->invoke($controller, ['name' => '', 'phone' => null], $customer, 'company-uuid'))->toBeNull()
        ->and($resolver->invoke($controller, 12345, $customer, 'company-uuid'))->toBeNull();
});
