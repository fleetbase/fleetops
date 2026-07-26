<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\FleetOps\Support\Ai\Capabilities\AssetStatusCapability;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OperationalQueryCapability;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OrderInsightsCapability;
use Fleetbase\FleetOps\Support\Ai\FleetOpsAiQueryResources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

if (!class_exists('Fleetbase\Ai\Services\AiQueryExecutor')) {
    eval('namespace Fleetbase\Ai\Services; class AiQueryExecutor {}');
}

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

function fleetopsOrderInsightsCapability()
{
    return (new ReflectionClass(OrderInsightsCapability::class))->newInstanceWithoutConstructor();
}

function fleetopsOrderInsightsProtectedMethod(string $method): ReflectionMethod
{
    $reflection = new ReflectionClass(OrderInsightsCapability::class);
    $method     = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method;
}

class FleetOpsOperationalQueryBuilderRecorder extends Builder
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function whereNotNull($columns, $boolean = 'and')
    {
        $this->calls[] = ['whereNotNull', $columns, $boolean];

        return $this;
    }

    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $this->calls[] = ['whereRaw', trim($sql), $bindings, $boolean];

        return $this;
    }
}

class FleetOpsAiQueryExecutorRecorder extends Fleetbase\Ai\Services\AiQueryExecutor
{
    public array $calls = [];

    public function count(string $resource, array $filters = []): int
    {
        $this->calls[] = ['count', $resource, $filters];

        return count($this->calls);
    }

    public function countsBy(string $resource, string $field, array $filters = []): array
    {
        $this->calls[] = ['countsBy', $resource, $field, $filters];

        return [
            ['value' => 'active', 'count' => 2],
            ['value' => 'inactive', 'count' => 1],
        ];
    }

    public function samples(string $resource, array $filters = [], int $limit = 10): array
    {
        $this->calls[] = ['samples', $resource, $filters, $limit];

        return [['public_id' => 'DRV-1']];
    }

    public function locationSummary(string $resource, array $filters = [], int $limit = 250): array
    {
        $this->calls[] = ['locationSummary', $resource, $filters, $limit];

        return [
            'resource' => $resource,
            'filters'  => $filters,
            'limit'    => $limit,
        ];
    }
}

class FleetOpsOperationalQueryCapabilityProbe extends OperationalQueryCapability
{
    public ?array $window     = null;
    public array $permissions = [];

    protected function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    protected function dateWindow(string $prompt): ?array
    {
        return $this->window;
    }

    public function callDriverQueries(string $prompt, Fleetbase\Ai\Services\AiQueryExecutor $executor): array
    {
        return $this->driverQueries($prompt, $executor);
    }

    public function callOnlineResourceQueries(string $resource, string $prompt, Fleetbase\Ai\Services\AiQueryExecutor $executor): array
    {
        return $this->onlineResourceQueries($resource, $prompt, $executor);
    }

    public function callOrderQueries(string $prompt, Fleetbase\Ai\Services\AiQueryExecutor $executor): array
    {
        return $this->orderQueries($prompt, $executor);
    }

    public function callDriverGeofenceDistribution(array $filters = []): array
    {
        return $this->driverGeofenceDistribution($filters);
    }
}

function fleetopsOperationalQueryProbe(?array $window = null, array $permissions = []): FleetOpsOperationalQueryCapabilityProbe
{
    $capability              = (new ReflectionClass(FleetOpsOperationalQueryCapabilityProbe::class))->newInstanceWithoutConstructor();
    $capability->window      = $window;
    $capability->permissions = $permissions;

    return $capability;
}

function fleetopsOperationalQueryWindow(): array
{
    return [
        'label'    => 'this week',
        'timezone' => 'Asia/Singapore',
        'start'    => Carbon::parse('2026-07-20 00:00:00', 'Asia/Singapore'),
        'end'      => Carbon::parse('2026-07-26 23:59:59', 'Asia/Singapore'),
    ];
}

class FleetOpsAssetStatusCapabilityProbe extends AssetStatusCapability
{
    public array $permissions = [];

    protected function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function callStatusCounts(string $modelClass, string $permission): array
    {
        return $this->statusCounts($modelClass, $permission);
    }

    public function callDeviceStatus(): array
    {
        return $this->deviceStatus();
    }

    public function callDriverStatus(): array
    {
        return $this->driverStatus();
    }
}

function fleetopsAssetStatusCapabilityProbe(array $permissions = []): FleetOpsAssetStatusCapabilityProbe
{
    $capability              = (new ReflectionClass(FleetOpsAssetStatusCapabilityProbe::class))->newInstanceWithoutConstructor();
    $capability->permissions = $permissions;

    return $capability;
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

test('operational query capability exposes metadata and helper contracts', function () {
    $capability        = fleetopsAiOperationalCapability();
    $dateFilters       = fleetopsAiOperationalProtectedMethod('dateFilters');
    $dateWindowPayload = fleetopsAiOperationalProtectedMethod('dateWindowPayload');
    $mentions          = fleetopsAiOperationalProtectedMethod('mentions');
    $validLocation     = fleetopsAiOperationalProtectedMethod('whereValidDriverLocation');

    $start  = Carbon::parse('2026-07-01 00:00:00', 'Asia/Singapore');
    $end    = Carbon::parse('2026-07-31 23:59:59', 'Asia/Singapore');
    $window = [
        'label'    => 'this month',
        'timezone' => 'Asia/Singapore',
        'start'    => $start,
        'end'      => $end,
    ];
    $builder = new FleetOpsOperationalQueryBuilderRecorder();

    expect($capability->key())->toBe('fleet-ops.operational_query')
        ->and($capability->label())->toBe('Fleet-Ops operational query')
        ->and($capability->description())->toContain('allowlisted Fleet-Ops')
        ->and($capability->permissions())->toBe([
            'fleet-ops list driver',
            'fleet-ops list vehicle',
            'fleet-ops list device',
            'fleet-ops list order',
            'fleet-ops list fleet',
            'fleet-ops list service-area',
            'fleet-ops list zone',
        ])
        ->and($dateFilters->invoke($capability, $window, 'updated_at'))->toBe([
            ['field' => 'updated_at', 'operator' => '>=', 'value' => $start],
            ['field' => 'updated_at', 'operator' => '<=', 'value' => $end],
        ])
        ->and($dateWindowPayload->invoke($capability, $window, 'last_online_at'))->toBe([
            'label'    => 'this month',
            'timezone' => 'Asia/Singapore',
            'field'    => 'last_online_at',
            'start'    => '2026-07-01T00:00:00+08:00',
            'end'      => '2026-07-31T23:59:59+08:00',
        ])
        ->and($mentions->invoke($capability, 'where are online drivers located?', ['driver', 'vehicle']))->toBeTrue()
        ->and($mentions->invoke($capability, 'show warehouse notes', ['driver', 'vehicle']))->toBeFalse()
        ->and($validLocation->invoke($capability, $builder))->toBe($builder)
        ->and($builder->calls)->toBe([
            ['whereNotNull', 'location', 'and'],
            ['whereRaw', 'ST_Y(location) BETWEEN -90 AND 90
            AND ST_X(location) BETWEEN -180 AND 180
            AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)', [], 'and'],
        ]);
});

test('operational query capability rejects unrelated prompts', function () {
    $capability = fleetopsAiOperationalCapability();
    $method     = fleetopsAiOperationalProtectedMethod('matchesPrompt');

    expect($method->invoke($capability, 'show support tickets'))->toBeFalse()
        ->and($method->invoke($capability, 'drivers'))->toBeFalse()
        ->and($method->invoke($capability, 'how many invoices are overdue'))->toBeFalse();
});

test('operational query capability composes driver location and assignment summaries', function () {
    $executor   = new FleetOpsAiQueryExecutorRecorder();
    $capability = fleetopsOperationalQueryProbe(fleetopsOperationalQueryWindow());
    $queries    = $capability->callDriverQueries('where are online drivers without vehicle this week by service area', $executor);

    expect($queries['date_window'])->toMatchArray([
        'label'    => 'this week',
        'timezone' => 'Asia/Singapore',
        'field'    => 'updated_at',
    ])
        ->and($queries['without_vehicle']['samples'])->toBe([['public_id' => 'DRV-1']])
        ->and($queries['location_summary']['resource'])->toBe('fleet-ops.drivers')
        ->and($queries['location_summary']['limit'])->toBe(250)
        ->and($queries['service_area_distribution'])->toBe([
            'authorized' => false,
            'resource'   => 'fleet-ops.drivers',
        ])
        ->and($executor->calls)->toContain(
            ['count', 'fleet-ops.drivers', []],
            ['count', 'fleet-ops.drivers', [['field' => 'online', 'operator' => '=', 'value' => true]]],
            ['count', 'fleet-ops.drivers', [['field' => 'online', 'operator' => 'false_or_null']]],
            ['countsBy', 'fleet-ops.drivers', 'status', []],
            ['samples', 'fleet-ops.drivers', [['field' => 'vehicle_uuid', 'operator' => 'null']], 10]
        );

    $locationCall = collect($executor->calls)->firstWhere(0, 'locationSummary');

    expect($locationCall[2])->toHaveCount(3)
        ->and($locationCall[2][0])->toMatchArray(['field' => 'updated_at', 'operator' => '>='])
        ->and($locationCall[2][1])->toMatchArray(['field' => 'updated_at', 'operator' => '<='])
        ->and($locationCall[2][2])->toBe(['field' => 'online', 'operator' => '=', 'value' => true]);
});

test('operational query capability composes vehicle and device online summaries', function () {
    $executor   = new FleetOpsAiQueryExecutorRecorder();
    $capability = fleetopsOperationalQueryProbe(fleetopsOperationalQueryWindow());

    $vehicleQueries = $capability->callOnlineResourceQueries('fleet-ops.vehicles', 'where are online vehicles this week', $executor);
    $deviceQueries  = $capability->callOnlineResourceQueries('fleet-ops.devices', 'how many devices online this week', $executor);

    expect($vehicleQueries['date_window'])->toMatchArray([
        'field' => 'updated_at',
        'label' => 'this week',
    ])
        ->and($vehicleQueries['location_summary']['resource'])->toBe('fleet-ops.vehicles')
        ->and($deviceQueries['date_window'])->toMatchArray([
            'field' => 'last_online_at',
            'label' => 'this week',
        ])
        ->and($deviceQueries)->not->toHaveKey('location_summary');
});

test('operational query capability composes active order assignment summaries', function () {
    $executor   = new FleetOpsAiQueryExecutorRecorder();
    $capability = fleetopsOperationalQueryProbe(fleetopsOperationalQueryWindow());
    $queries    = $capability->callOrderQueries('active orders without driver with driver without vehicle this week', $executor);

    expect($queries['date_window'])->toMatchArray([
        'field' => 'created_at',
        'label' => 'this week',
    ])
        ->and($queries)->toHaveKeys(['total', 'counts_by_status', 'without_driver', 'with_driver', 'without_vehicle']);

    $totalCall = $executor->calls[0];

    expect($totalCall[0])->toBe('count')
        ->and($totalCall[1])->toBe('fleet-ops.orders')
        ->and($totalCall[2])->toHaveCount(3)
        ->and($totalCall[2][0])->toMatchArray(['field' => 'created_at', 'operator' => '>='])
        ->and($totalCall[2][1])->toMatchArray(['field' => 'created_at', 'operator' => '<='])
        ->and($totalCall[2][2])->toBe(['field' => 'status', 'operator' => 'not_in', 'value' => ['canceled', 'completed', 'expired']]);
});

test('operational query capability resolves all mentioned resources through executor summaries', function () {
    $executor = new FleetOpsAiQueryExecutorRecorder();

    app()->instance(Fleetbase\Ai\Services\AiQueryExecutor::class, $executor);

    $capability = fleetopsOperationalQueryProbe(fleetopsOperationalQueryWindow());
    $result     = $capability->resolve(new AiTask(['prompt' => 'count drivers vehicles devices orders and fleets this week']));

    expect($result['authorized'])->toBeTrue()
        ->and($result['query_engine'])->toBe('fleetbase_ai_allowlisted_operational_query')
        ->and($result['instruction'])->toContain('Answer only from these executed Fleetbase query summaries')
        ->and($result['queries'])->toHaveKeys(['drivers', 'vehicles', 'devices', 'orders', 'fleets'])
        ->and($result['queries']['fleets']['total'])->toBeInt();
});

test('asset status capability includes driver online prompts', function () {
    $capability = (new ReflectionClass(AssetStatusCapability::class))->newInstanceWithoutConstructor();
    $method     = (new ReflectionClass(AssetStatusCapability::class))->getMethod('matchesPrompt');
    $method->setAccessible(true);

    expect($method->invoke($capability, 'how many drivers are online'))->toBeTrue()
        ->and($method->invoke($capability, 'show unrelated warehouse notes'))->toBeFalse();
});

test('asset status capability exposes metadata and denied branches', function () {
    $capability = fleetopsAssetStatusCapabilityProbe();

    expect($capability->key())->toBe('fleet-ops.asset_status')
        ->and($capability->label())->toBe('Fleet-Ops asset status')
        ->and($capability->description())->toContain('vehicle, device, sensor')
        ->and($capability->permissions())->toBe([
            'fleet-ops see vehicle',
            'fleet-ops see device',
            'fleet-ops see sensor',
            'fleet-ops see telematic',
        ])
        ->and($capability->callStatusCounts(Fleetbase\FleetOps\Models\Vehicle::class, 'fleet-ops see vehicle'))->toBe(['authorized' => false])
        ->and($capability->callDeviceStatus())->toBe(['authorized' => false])
        ->and($capability->callDriverStatus())->toBe(['authorized' => false]);
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

test('order insights capability exposes metadata prompt matching thresholds and date windows', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Singapore');
    Carbon::setTestNow(Carbon::parse('2026-06-30 15:00:00', 'Asia/Singapore'));

    try {
        $capability      = fleetopsOrderInsightsCapability();
        $matchesPrompt   = fleetopsOrderInsightsProtectedMethod('matchesPrompt');
        $amountThreshold = fleetopsOrderInsightsProtectedMethod('amountThreshold');
        $dateWindow      = fleetopsOrderInsightsProtectedMethod('dateWindow');

        expect($capability->key())->toBe('fleet-ops.order_insights')
            ->and($capability->label())->toBe('Fleet-Ops order insights')
            ->and($capability->description())->toContain('Fleet-Ops orders')
            ->and($capability->permissions())->toBe(['fleet-ops see order'])
            ->and($matchesPrompt->invoke($capability, 'how many orders completed last week'))->toBeTrue()
            ->and($matchesPrompt->invoke($capability, 'show vehicle locations'))->toBeFalse()
            ->and($amountThreshold->invoke($capability, 'orders over $125.50 last week'))->toBe(125.50)
            ->and($amountThreshold->invoke($capability, 'orders greater than 40'))->toBe(40.0)
            ->and($amountThreshold->invoke($capability, 'orders without a value threshold'))->toBeNull();

        $window = $dateWindow->invoke($capability, 'orders from last week');

        expect($window['label'])->toBe('last week')
            ->and($window['timezone'])->toBe('Asia/Singapore')
            ->and($window['start']->toIso8601String())->toBe('2026-06-22T00:00:00+08:00')
            ->and($window['end']->toIso8601String())->toBe('2026-06-28T23:59:59+08:00');
    } finally {
        Carbon::setTestNow();
        date_default_timezone_set($timezone);
    }
});
