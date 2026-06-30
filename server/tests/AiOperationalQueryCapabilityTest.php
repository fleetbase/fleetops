<?php

use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\FleetOps\Support\Ai\Capabilities\AssetStatusCapability;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OperationalQueryCapability;
use Fleetbase\FleetOps\Support\Ai\FleetOpsAiQueryResources;
use Illuminate\Support\Carbon;

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
    'How many vehicles were online yesterday?',
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

test('operational query date filters use resolved local windows', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Singapore');
    Carbon::setTestNow(Carbon::parse('2026-06-30 15:00:00', 'Asia/Singapore'));

    try {
        $capability = fleetopsAiOperationalCapability();
        $method     = fleetopsAiOperationalProtectedMethod('orderDateFilters');
        $filters    = $method->invoke($capability, 'How many orders were created last week?');

        expect($filters)->toHaveCount(2)
            ->and($filters[0])->toMatchArray(['field' => 'created_at', 'operator' => '>='])
            ->and($filters[0]['value']->toIso8601String())->toBe('2026-06-22T00:00:00+08:00')
            ->and($filters[1]['value']->toIso8601String())->toBe('2026-06-28T23:59:59+08:00');
    } finally {
        Carbon::setTestNow();
        date_default_timezone_set($timezone);
    }
});
