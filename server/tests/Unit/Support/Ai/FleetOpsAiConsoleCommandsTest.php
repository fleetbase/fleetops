<?php

use Fleetbase\FleetOps\Support\Ai\FleetOpsAiConsoleCommands;

test('fleet-ops console commands are unique, complete, and only navigate or call allowlisted services', function () {
    $commands = collect(FleetOpsAiConsoleCommands::all());

    expect($commands->pluck('id')->duplicates())->toBeEmpty()
        ->and($commands->every(fn ($command) => filled($command['label']) && filled($command['breadcrumb']) && filled($command['description']) && !empty($command['steps'])))->toBeTrue()
        ->and($commands->every(fn ($command) => collect($command['steps'])->every(fn ($step) => $step['type'] === 'navigate'
            ? str_starts_with($step['route'], 'console.fleet-ops.')
            : $step['type'] === 'service' && $step['engine'] === FleetOpsAiConsoleCommands::ENGINE && preg_match('/^[a-zA-Z]+$/', $step['method']))))->toBeTrue()
        ->and($commands->every(fn ($command) => ($command['audience'] ?? 'end_user') === 'end_user'))->toBeTrue();
});

test('fleet-ops console commands cover create, import, view, and settings', function () {
    $commands = collect(FleetOpsAiConsoleCommands::all())->keyBy('id');

    expect($commands['fleet-ops.orders.create']['steps'])->toBe([['type' => 'navigate', 'route' => 'console.fleet-ops.operations.orders.index.new']])
        ->and($commands['fleet-ops.orders.create']['permissions'])->toBe(['fleet-ops create order'])
        ->and($commands['fleet-ops.orders.import']['steps'][1])->toBe(['type' => 'service', 'engine' => '@fleetbase/fleetops-engine', 'service' => 'order-actions', 'method' => 'importOrders'])
        ->and($commands['fleet-ops.customers.import']['steps'][1]['service'])->toBe('customer-actions')
        ->and($commands['fleet-ops.customers.import']['permissions'])->toBe(['fleet-ops import contact'])
        ->and($commands)->not->toHaveKey('fleet-ops.fleets.import')
        ->and($commands['fleet-ops.drivers.view']['steps'][0]['models'])->toBe(['public_id'])
        ->and($commands['fleet-ops.drivers.view']['params'])->toHaveKey('public_id')
        ->and($commands['fleet-ops.settings.map.open']['steps'][0]['route'])->toBe('console.fleet-ops.settings.map')
        ->and($commands['fleet-ops.settings.map.open']['permissions'])->toBe(['fleet-ops view map-settings'])
        ->and($commands['fleet-ops.settings.map.open']['keywords'])->toContain('google maps', 'view maps')
        ->and($commands['fleet-ops.settings.scheduling.open']['permissions'])->toBe([]);
});
