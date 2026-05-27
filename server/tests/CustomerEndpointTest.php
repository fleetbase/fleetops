<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Middleware\AuthenticateCustomerToken;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
use Fleetbase\FleetOps\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Customer as CustomerResource;
use Fleetbase\FleetOps\Models\Customer as CustomerModel;
use Fleetbase\FleetOps\Support\CustomerAuth;

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

test('Customer model extends Contact with a type=customer global scope', function () {
    expect(is_subclass_of(CustomerModel::class, Fleetbase\FleetOps\Models\Contact::class))->toBeTrue();

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
