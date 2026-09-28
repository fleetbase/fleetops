<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrchestrationController;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

function sanitize_orchestrator_payload(array $payload): array
{
    $controller = new OrchestrationController(new OrchestrationEngineRegistry());
    $method     = new ReflectionMethod($controller, 'sanitizePublicPayload');
    $method->setAccessible(true);

    return $method->invoke($controller, $payload);
}

function assert_no_internal_orchestrator_identifiers(array $payload): void
{
    foreach ($payload as $key => $value) {
        expect($key)->not->toBe('uuid');
        expect($key)->not->toBe('company_uuid');
        expect($key)->not->toBe('internal_id');
        expect(str_ends_with((string) $key, '_uuid'))->toBeFalse();

        if (is_array($value)) {
            assert_no_internal_orchestrator_identifiers($value);
        }
    }
}

test('consumable orchestrator responses remove internal identifiers recursively', function () {
    $payload = [
        'assignments' => [
            [
                'order_id'      => 'order_123',
                'order_uuid'    => '6a76d5ef-9d08-4eb9-a235-62406d6a0ed2',
                'vehicle_id'    => 'vehicle_123',
                'vehicle_uuid'  => '9a05d9d1-9ff7-460f-a6ee-509a0ed9b345',
                'driver_id'     => 'driver_123',
                'internal_id'   => 123,
                'nested'        => [
                    'uuid'         => '793f2f9d-5225-49f7-ad22-d73a2c4ea2d1',
                    'company_uuid' => 'company-internal',
                    'public_id'    => 'safe_public_id',
                ],
            ],
        ],
        'unassigned' => ['order_456'],
        'summary'    => [
            'routes' => 1,
            'meta'   => [
                'request_uuid' => '524c5175-1df2-4e9a-a27b-3107f9e08545',
            ],
        ],
    ];

    $sanitized = sanitize_orchestrator_payload($payload);

    expect($sanitized['assignments'][0])->toMatchArray([
        'order_id'   => 'order_123',
        'vehicle_id' => 'vehicle_123',
        'driver_id'  => 'driver_123',
    ]);
    expect($sanitized['assignments'][0]['nested']['public_id'])->toBe('safe_public_id');
    expect($sanitized['summary']['routes'])->toBe(1);

    assert_no_internal_orchestrator_identifiers($sanitized);
});

test('consumable orchestrator routes are registered under the public api group', function () {
    $routes = file_get_contents(__DIR__ . '/../src/routes.php');

    expect($routes)->toContain("['prefix' => 'orchestrator']");
    expect($routes)->toContain("\$router->post('run', 'OrchestrationController@run');");
    expect($routes)->toContain("\$router->post('commit', 'OrchestrationController@commit');");
});

test('public orchestration uses API authentication without inheriting console IAM middleware', function () {
    $registry = new OrchestrationEngineRegistry();
    $public   = new OrchestrationController($registry);
    $internal = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController($registry);

    expect($public->getMiddleware())->toBe([]);

    // An API-key request has no console IAM role. The internal workbench must
    // still refuse this actor; its middleware must not leak onto the API class.
    app('session.store')->flush();
    $request = Request::create('/int/v1/fleet-ops/orchestrator/run', 'POST');
    app()->instance('request', $request);
    foreach (['run', 'commit'] as $method) {
        $guard = collect($internal->getMiddleware())->first(fn ($entry) => in_array($method, $entry['options']['only'], true));
        expect($guard)->not->toBeNull();

        try {
            $guard['middleware']($request, fn () => throw new RuntimeException('Unauthorized request reached the workbench'));
            test()->fail('The internal workbench must require a permitted user');
        } catch (HttpResponseException $error) {
            expect($error->getResponse()->getStatusCode())->toBe(401);
        }
    }
});

test('public orchestration resolves tenant scope from the authenticated session, never request input', function () {
    $controller = new OrchestrationController(new OrchestrationEngineRegistry());
    $company    = new ReflectionMethod($controller, 'companyUuid');
    $company->setAccessible(true);
    $request = Request::create('/v1/orchestrator/run', 'POST', ['company_uuid' => 'other-company']);
    app()->instance('request', $request);

    session(['company' => 'credential-company']);
    expect($company->invoke($controller))->toBe('credential-company');

    session(['company' => 'next-credential-company']);
    expect($company->invoke($controller))->toBe('next-credential-company');
    app('session.store')->flush();
});
