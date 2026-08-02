<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Support\Ai\Capabilities\ConsoleNavigationCapability;
use Fleetbase\FleetOps\Support\Ai\Capabilities\DocsHelpCapability;
use Fleetbase\FleetOps\Support\Ai\Capabilities\ImportOrdersPreviewCapability;

class FleetOpsImportOrdersPreviewCapabilityFake extends ImportOrdersPreviewCapability
{
    public bool $authorized = true;

    protected function can(string $permission): bool
    {
        return $permission === 'fleet-ops import order' && $this->authorized;
    }

    public function promptMatches(string $prompt): bool
    {
        $method = new ReflectionMethod(ImportOrdersPreviewCapability::class, 'matchesPrompt');
        $method->setAccessible(true);

        return $method->invoke($this, $prompt);
    }
}

class FleetOpsDocsHelpCapabilityFake extends DocsHelpCapability
{
    public function promptMatches(string $prompt): bool
    {
        $method = new ReflectionMethod(DocsHelpCapability::class, 'matchesPrompt');
        $method->setAccessible(true);

        return $method->invoke($this, $prompt);
    }
}

class FleetOpsConsoleNavigationCapabilityFake extends ConsoleNavigationCapability
{
    public array $orders       = [];
    public array $vehicles     = [];
    public array $drivers      = [];
    public array $workOrders   = [];
    public array $maintenances = [];
    public array $devices      = [];
    public array $sensors      = [];
    public array $telematics   = [];

    public function promptMatches(string $prompt): bool
    {
        $method = new ReflectionMethod(ConsoleNavigationCapability::class, 'matchesPrompt');
        $method->setAccessible(true);

        return $method->invoke($this, $prompt);
    }

    protected function orders(array $terms): array
    {
        return $this->orders;
    }

    protected function vehicles(array $terms): array
    {
        return $this->vehicles;
    }

    protected function drivers(array $terms): array
    {
        return $this->drivers;
    }

    protected function workOrders(array $terms): array
    {
        return $this->workOrders;
    }

    protected function maintenances(array $terms): array
    {
        return $this->maintenances;
    }

    protected function devices(array $terms): array
    {
        return $this->devices;
    }

    protected function sensors(array $terms): array
    {
        return $this->sensors;
    }

    protected function telematics(array $terms): array
    {
        return $this->telematics;
    }
}

test('import orders preview capability exposes metadata prompt matching and authorization payload', function () {
    $capability = new FleetOpsImportOrdersPreviewCapabilityFake();
    $task       = new AiTask(['prompt' => 'Import these order rows from a CSV spreadsheet']);

    expect($capability->key())->toBe('fleet-ops.import_orders_preview')
        ->and($capability->label())->toBe('Import Fleet-Ops orders preview')
        ->and($capability->description())->toContain('preview-only Fleet-Ops import requirements')
        ->and($capability->type())->toBe('action')
        ->and($capability->mode())->toBe('preview')
        ->and($capability->permissions())->toBe(['fleet-ops import order'])
        ->and($capability->module())->toBe('fleet-ops')
        ->and($capability->shouldResolve($task))->toBeTrue()
        ->and($capability->promptMatches('upload xlsx order resources'))->toBeTrue()
        ->and($capability->promptMatches('summarize maintenance docs'))->toBeFalse();

    $preview = $capability->resolve($task);

    expect($preview)->toMatchArray([
        'preview_only'     => true,
        'action'           => 'import_orders',
        'authorized'       => true,
        'accepted_sources' => ['xlsx', 'csv'],
    ])
        ->and($preview['minimum_columns'])->toContain('pickup address or pickup place', 'dropoff address or dropoff place')
        ->and($preview['draft_hints'])->toContain('Do not claim rows were imported.');

    $capability->authorized = false;

    expect($capability->resolve($task)['authorized'])->toBeFalse();
});

test('docs help capability selects references from prompt intent', function () {
    $capability = new FleetOpsDocsHelpCapabilityFake();
    $task       = new AiTask(['prompt' => 'How do I create an order and set up the Navigator driver app with maintenance docs?']);

    $references = $capability->resolve($task)['references'];

    expect($capability->key())->toBe('fleet-ops.docs_help')
        ->and($capability->label())->toBe('Fleet-Ops docs help')
        ->and($capability->description())->toContain('official Fleetbase documentation')
        ->and($capability->shouldResolve($task))->toBeTrue()
        ->and($capability->promptMatches('where do i find analytics reports documentation'))->toBeTrue()
        ->and($capability->promptMatches('dispatch this order now'))->toBeFalse()
        ->and(collect($references)->pluck('title')->all())->toBe([
            'Fleet-Ops overview',
            'Fleet-Ops quickstart',
            'Navigator app setup',
            'Maintenance overview',
        ])
        ->and($references[1]['url'])->toBe('https://fleetbase.io/docs/fleet-ops/getting-started/quickstart');
});

test('console navigation capability filters route suggestions from resource search results', function () {
    $capability         = new FleetOpsConsoleNavigationCapabilityFake();
    $capability->orders = [
        ['id' => 'order_1', 'route' => 'console.fleet-ops.operations.orders.index.details'],
        ['id' => 'order_without_route'],
    ];
    $capability->vehicles = [
        ['id' => 'vehicle_1', 'route' => 'console.fleet-ops.management.vehicles.index.details'],
    ];
    $capability->drivers = [
        ['id' => 'driver_1', 'route' => 'console.fleet-ops.management.drivers.index.details'],
        ['id' => 'driver_2', 'route' => 'console.fleet-ops.management.drivers.index.details'],
        ['id' => 'driver_3', 'route' => 'console.fleet-ops.management.drivers.index.details'],
        ['id' => 'driver_4', 'route' => 'console.fleet-ops.management.drivers.index.details'],
    ];
    $task = new AiTask(['prompt' => 'Open driver DRIVER-1 in Fleet-Ops']);

    $preview = $capability->resolve($task);

    expect($capability->key())->toBe('fleet-ops.console_navigation')
        ->and($capability->label())->toBe('Fleet-Ops console navigation preview')
        ->and($capability->description())->toContain('console route suggestions')
        ->and($capability->type())->toBe('ui')
        ->and($capability->mode())->toBe('navigation_preview')
        ->and($capability->shouldResolve($task))->toBeTrue()
        ->and($capability->promptMatches('go to vehicle VH-1'))->toBeTrue()
        ->and($capability->promptMatches('find vehicle VH-1'))->toBeFalse()
        ->and($preview['preview_only'])->toBeTrue()
        ->and($preview['message'])->toContain('does not directly open panels yet')
        ->and(collect($preview['suggestions'])->pluck('id')->all())->toBe(['order_1', 'vehicle_1', 'driver_1', 'driver_2', 'driver_3'])
        ->and($preview['suggestions'])->toHaveCount(5);
});
