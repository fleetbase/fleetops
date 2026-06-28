<?php

use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\FleetOps\Support\Ai\Capabilities\AssetStatusCapability;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OperationalQueryCapability;
use Fleetbase\FleetOps\Support\Ai\FleetOpsAiQueryResources;

function fleetopsAiOperationalCapability()
{
    return (new ReflectionClass(OperationalQueryCapability::class))->newInstanceWithoutConstructor();
}

function fleetopsAiOperationalProtectedMethod(string $method): ReflectionMethod
{
    $reflection = new ReflectionClass(OperationalQueryCapability::class);
    $method     = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method;
}

test('fleet-ops registers safe ai query resources', function () {
    $registry = new AiQueryRegistry();

    FleetOpsAiQueryResources::register($registry);

    expect($registry->find('drivers')->key)->toBe('fleet-ops.drivers')
        ->and($registry->find('orders')->hasField('driver_assigned_uuid'))->toBeTrue()
        ->and($registry->find('devices')->hasField('online'))->toBeTrue()
        ->and($registry->find('service area')->key)->toBe('fleet-ops.service_areas');
});

test('operational query capability matches common fleet-ops data questions', function (string $prompt) {
    $capability = fleetopsAiOperationalCapability();
    $method     = fleetopsAiOperationalProtectedMethod('matchesPrompt');

    expect($method->invoke($capability, strtolower($prompt)))->toBeTrue();
})->with([
    'How many drivers are currently online?',
    'Where are most of my drivers located?',
    'Which service area has the most online drivers?',
    'Show me drivers without vehicles.',
    'How many active orders have no assigned driver?',
    'Break down orders by status for this month.',
]);

test('asset status capability includes driver online prompts', function () {
    $capability = (new ReflectionClass(AssetStatusCapability::class))->newInstanceWithoutConstructor();
    $method     = (new ReflectionClass(AssetStatusCapability::class))->getMethod('matchesPrompt');
    $method->setAccessible(true);

    expect($method->invoke($capability, 'how many drivers are online'))->toBeTrue();
});
