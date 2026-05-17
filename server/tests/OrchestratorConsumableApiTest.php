<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrchestrationController;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;

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
