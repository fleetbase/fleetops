<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\AnalyticsController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics;
use Fleetbase\FleetOps\Support\Analytics\FuelEfficiency;
use Fleetbase\FleetOps\Support\Analytics\FuelProviderSummary;
use Fleetbase\FleetOps\Support\Analytics\GeofenceViolations;
use Fleetbase\FleetOps\Support\Analytics\IssuesInsights;
use Fleetbase\FleetOps\Support\Analytics\LiveFleet;
use Fleetbase\FleetOps\Support\Analytics\MaintenanceOverview;
use Fleetbase\FleetOps\Support\Analytics\OnTimeDelivery;
use Fleetbase\FleetOps\Support\Analytics\OperationsPulse;
use Fleetbase\FleetOps\Support\Analytics\OrdersByStatus;
use Fleetbase\FleetOps\Support\Analytics\RevenueTrend;
use Fleetbase\FleetOps\Support\Analytics\TopDrivers;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TestFleetOpsLiveFleetDriver extends Driver
{
    public array $fakeAttributes = [];

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->fakeAttributes)) {
            return $this->fakeAttributes[$key];
        }

        return parent::getAttribute($key);
    }
}

class TestFleetOpsLiveFleetVehicle extends Vehicle
{
    public array $fakeAttributes = [];

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->fakeAttributes)) {
            return $this->fakeAttributes[$key];
        }

        return parent::getAttribute($key);
    }
}

class TestFleetOpsAnalytics extends AbstractAnalytics
{
    public function get(): array
    {
        return [
            'company'  => $this->company->uuid,
            'currency' => $this->companyCurrency(),
            'start'    => $this->start?->format('Y-m-d'),
            'end'      => $this->end?->format('Y-m-d'),
        ];
    }
}

class TestFleetOpsAnalyticsControllerWidget extends AbstractAnalytics
{
    public array $config         = [];
    public string $class         = '';
    public ?Throwable $throwable = null;

    public function groupBy(string $groupBy): self
    {
        $this->config['group_by'] = $groupBy;

        return $this;
    }

    public function slaMinutes(int $minutes): self
    {
        $this->config['sla_minutes'] = $minutes;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->config['limit'] = $limit;

        return $this;
    }

    public function sortBy(string $sortBy): self
    {
        $this->config['sort_by'] = $sortBy;

        return $this;
    }

    public function get(): array
    {
        if ($this->throwable) {
            throw $this->throwable;
        }

        return [
            'class'   => $this->class,
            'company' => $this->company->uuid,
            'start'   => $this->start?->format('Y-m-d'),
            'end'     => $this->end?->format('Y-m-d'),
            'config'  => $this->config,
        ];
    }
}

class TestFleetOpsAnalyticsControllerProbe extends AnalyticsController
{
    public array $widgets        = [];
    public ?Throwable $throwable = null;

    protected function analyticsForCompany(string $class, $company): AbstractAnalytics
    {
        $widget            = TestFleetOpsAnalyticsControllerWidget::forCompany($company);
        $widget->class     = $class;
        $widget->throwable = $this->throwable;
        $this->widgets[]   = $widget;

        return $widget;
    }
}

function fleetOpsAnalyticsControllerRequest(array $input = []): Request
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-analytics', 'currency' => 'USD'], true);

    $request = new Request($input);
    $request->setUserResolver(fn () => (object) ['company' => $company]);

    return $request;
}

test('analytics routes are registered alongside metrics routes', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)->toContain("['prefix' => 'analytics']");
    expect($routes)->toContain("'AnalyticsController@operationsPulse'");
    expect($routes)->toContain("'AnalyticsController@revenueTrend'");
    expect($routes)->toContain("'AnalyticsController@ordersByStatus'");
    expect($routes)->toContain("'AnalyticsController@onTimeDelivery'");
    expect($routes)->toContain("'AnalyticsController@topDrivers'");
    expect($routes)->toContain("'AnalyticsController@fuelEfficiency'");
    expect($routes)->toContain("'AnalyticsController@issuesInsights'");
    expect($routes)->toContain("'AnalyticsController@maintenanceOverview'");
    expect($routes)->toContain("'AnalyticsController@geofenceViolations'");
    expect($routes)->toContain("'AnalyticsController@liveFleet'");
});

test('per-slug metrics endpoint is registered', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)->toContain("\$router->get('{slug}', 'MetricsController@show');");
});

test('legacy metrics endpoint is preserved for backward compat', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    expect($routes)->toContain("\$router->get('/', 'MetricsController@all');");
});

test('analytics controller has one method per widget', function () {
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/AnalyticsController.php');

    foreach ([
        'operationsPulse', 'revenueTrend', 'ordersByStatus', 'onTimeDelivery',
        'topDrivers', 'fuelEfficiency', 'issuesInsights', 'maintenanceOverview',
        'geofenceViolations', 'liveFleet',
    ] as $method) {
        expect($controller)->toContain("public function {$method}(");
    }
});

test('analytics controller runs each widget with period and request configuration', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    $controller = new TestFleetOpsAnalyticsControllerProbe();

    $cases = [
        'operationsPulse'      => [OperationsPulse::class, [], '2026-06-26', []],
        'revenueTrend'         => [RevenueTrend::class, ['group_by' => 'week'], '2026-06-26', ['group_by' => 'week']],
        'ordersByStatus'       => [OrdersByStatus::class, [], '2026-07-12', []],
        'onTimeDelivery'       => [OnTimeDelivery::class, ['sla_minutes' => 45], '2026-06-26', ['sla_minutes' => 45]],
        'topDrivers'           => [TopDrivers::class, ['limit' => 3, 'sort_by' => 'on_time'], '2026-06-26', ['limit' => 3, 'sort_by' => 'on_time']],
        'fuelEfficiency'       => [FuelEfficiency::class, [], '2026-04-27', []],
        'fuelProviders'        => [FuelProviderSummary::class, [], '2026-06-26', []],
        'issuesInsights'       => [IssuesInsights::class, [], '2026-06-26', []],
        'maintenanceOverview'  => [MaintenanceOverview::class, [], '2026-06-26', []],
        'geofenceViolations'   => [GeofenceViolations::class, [], '2026-07-19', []],
        'liveFleet'            => [LiveFleet::class, [], '2026-06-26', []],
    ];

    foreach ($cases as $method => [$class, $input, $start, $config]) {
        $response = $controller->{$method}(fleetOpsAnalyticsControllerRequest($input));
        $payload  = $response->getData(true);

        expect($payload)->toMatchArray([
            'class'   => $class,
            'company' => 'company-analytics',
            'start'   => $start,
            'end'     => '2026-07-26',
            'config'  => $config,
        ]);
    }

    $controller->throwable = new RuntimeException('widget unavailable');
    $response              = $controller->operationsPulse(fleetOpsAnalyticsControllerRequest());

    expect($controller->widgets)->toHaveCount(12)
        ->and($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toMatchArray([
            'error'  => 'widget unavailable',
            'widget' => OperationsPulse::class,
        ]);

    Carbon::setTestNow();
});

test('top drivers on-time sorting uses a sql aggregate expression', function () {
    $widget = file_get_contents(dirname(__DIR__) . '/src/Support/Analytics/TopDrivers.php');

    expect($widget)
        ->toContain("'on_time'")
        ->toContain('CASE')
        ->toContain("Utils::sqlSecondsDiff('orders.scheduled_at', 'orders.updated_at')")
        ->not->toContain("'on_time'  => 'on_time_pct'");
});

test('analytics base resolves shorthand explicit and default periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

    [$start7d, $end7d]             = AbstractAnalytics::resolvePeriod('7d', null, null);
    [$explicitStart, $explicitEnd] = AbstractAnalytics::resolvePeriod(
        null,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-02-01'),
    );
    [$defaultStart, $defaultEnd] = AbstractAnalytics::resolvePeriod('unknown', null, null, 10);

    Carbon::setTestNow();

    expect($start7d->format('Y-m-d'))->toBe('2026-07-12')
        ->and($end7d->format('Y-m-d'))->toBe('2026-07-19')
        ->and($explicitStart->format('Y-m-d'))->toBe('2026-01-01')
        ->and($explicitEnd->format('Y-m-d'))->toBe('2026-02-01')
        ->and($defaultStart->format('Y-m-d'))->toBe('2026-07-09')
        ->and($defaultEnd->format('Y-m-d'))->toBe('2026-07-19');
});

test('analytics base applies company currency and explicit date ranges', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-1',
        'currency' => 'MNT',
    ], true);

    $analytics = TestFleetOpsAnalytics::forCompany($company)
        ->between(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

    expect($analytics->get())->toBe([
        'company'  => 'company-1',
        'currency' => 'MNT',
        'start'    => '2026-03-01',
        'end'      => '2026-03-31',
    ]);
});

test('live fleet analytics serializes driver and vehicle map payloads', function () {
    $analytics = new LiveFleet();

    $driver                 = new TestFleetOpsLiveFleetDriver();
    $driver->fakeAttributes = [
        'uuid'                    => 'driver-uuid',
        'public_id'               => 'driver-public',
        'name'                    => 'Ada Driver',
        'avatar_url'              => 'https://example.test/avatar.png',
        'vehicle_avatar'          => 'https://example.test/vehicle-avatar.png',
        'online'                  => 1,
        'heading'                 => '93.5',
        'current_job_uuid'        => 'order-uuid',
        'location'                => new Point(1.30, 103.80),
        'last_location_update_at' => '2026-01-01 10:00:00',
    ];

    $vehicle                 = new TestFleetOpsLiveFleetVehicle();
    $vehicle->fakeAttributes = [
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle-public',
        'display_name' => 'Van 7',
        'plate_number' => 'SBA1234Z',
        'avatar_url'   => 'https://example.test/van-avatar.png',
        'photo_url'    => 'https://example.test/van-photo.png',
        'online'       => null,
        'driver_name'  => 'Ada Driver',
        'heading'      => null,
        'location'     => ['lat' => 1.31, 'lng' => 103.81],
    ];

    $driverPayload  = new ReflectionMethod(LiveFleet::class, 'driverPayload');
    $vehiclePayload = new ReflectionMethod(LiveFleet::class, 'vehiclePayload');
    $driverPayload->setAccessible(true);
    $vehiclePayload->setAccessible(true);

    expect($driverPayload->invoke($analytics, $driver))->toBe([
        'uuid'               => 'driver-uuid',
        'public_id'          => 'driver-public',
        'name'               => 'Ada Driver',
        'avatar_url'         => 'https://example.test/avatar.png',
        'vehicle_avatar'     => 'https://example.test/vehicle-avatar.png',
        'online'             => true,
        'heading'            => 93.5,
        'current_order_uuid' => 'order-uuid',
        'lat'                => 1.30,
        'lng'                => 103.80,
        'updated_at'         => '2026-01-01 10:00:00',
    ])->and($vehiclePayload->invoke($analytics, $vehicle))->toBe([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle-public',
        'name'         => 'Van 7',
        'plate_number' => 'SBA1234Z',
        'avatar_url'   => 'https://example.test/van-avatar.png',
        'photo_url'    => 'https://example.test/van-photo.png',
        'online'       => false,
        'driver_name'  => 'Ada Driver',
        'heading'      => 0.0,
        'lat'          => 1.31,
        'lng'          => 103.81,
    ]);
});

test('live fleet analytics extracts coordinates from supported location shapes', function () {
    $analytics = new LiveFleet();
    $method    = new ReflectionMethod(LiveFleet::class, 'extractLatLng');
    $method->setAccessible(true);

    expect($method->invoke($analytics, new Point(1.30, 103.80)))->toBe([1.30, 103.80])
        ->and($method->invoke($analytics, ['lat' => 1.31, 'lng' => 103.81]))->toBe([1.31, 103.81])
        ->and($method->invoke($analytics, [103.82, 1.32]))->toBe([1.32, 103.82])
        ->and($method->invoke($analytics, null))->toBe([null, null]);
});

test('fuel efficiency analytics builds weekly cost and cost per kilometer datasets', function () {
    $analytics = new FuelEfficiency();
    $method    = new ReflectionMethod(FuelEfficiency::class, 'buildFuelEfficiencyPayload');
    $method->setAccessible(true);

    $payload = $method->invoke(
        $analytics,
        collect([
            (object) ['wk' => 202601, 'wk_start' => '2026-01-05', 'total_cost' => '123.456'],
            (object) ['wk' => 202602, 'wk_start' => '2026-01-12', 'total_cost' => 50],
        ]),
        collect([
            202601 => 12345,
            202602 => 0,
        ]),
        'USD',
    );

    expect($payload['labels'])->toBe(['Jan 5', 'Jan 12'])
        ->and($payload['datasets'][0])->toMatchArray([
            'type'            => 'bar',
            'label'           => 'Fuel Cost',
            'data'            => [123.46, 50.0],
            'yAxisID'         => 'y1',
            'backgroundColor' => '#3485e2',
        ])
        ->and($payload['datasets'][1])->toMatchArray([
            'type'        => 'line',
            'label'       => 'Cost per km',
            'data'        => [10.0, null],
            'yAxisID'     => 'y2',
            'borderColor' => '#f59e0b',
            'tension'     => 0.3,
        ])
        ->and($payload['summary'])->toBe([
            'total_cost'      => 173.46,
            'currency'        => 'USD',
            'avg_cost_per_km' => 14.051,
        ]);

    $emptyPayload = $method->invoke($analytics, [], [], 'MNT');

    expect($emptyPayload['labels'])->toBe([])
        ->and($emptyPayload['datasets'][0]['data'])->toBe([])
        ->and($emptyPayload['datasets'][1]['data'])->toBe([])
        ->and($emptyPayload['summary'])->toBe([
            'total_cost'      => 0.0,
            'currency'        => 'MNT',
            'avg_cost_per_km' => 0.0,
        ]);
});

test('analytics widget fluent options clamp or normalize invalid values', function () {
    $limitProperty = new ReflectionProperty(TopDrivers::class, 'limit');
    $limitProperty->setAccessible(true);
    $sortProperty = new ReflectionProperty(TopDrivers::class, 'sortBy');
    $sortProperty->setAccessible(true);
    $slaProperty = new ReflectionProperty(OnTimeDelivery::class, 'slaMinutes');
    $slaProperty->setAccessible(true);
    $groupProperty = new ReflectionProperty(RevenueTrend::class, 'groupBy');
    $groupProperty->setAccessible(true);

    $topDrivers = (new TopDrivers())->limit(500)->sortBy('unsupported');
    $onTime     = (new OnTimeDelivery())->slaMinutes(-5);
    $revenue    = (new RevenueTrend())->groupBy('quarter');

    expect($limitProperty->getValue($topDrivers))->toBe(50)
        ->and($sortProperty->getValue($topDrivers))->toBe('orders_completed')
        ->and($slaProperty->getValue($onTime))->toBe(0)
        ->and($groupProperty->getValue($revenue))->toBe('day');
});

test('operations pulse delta helper handles zero and non-zero previous values', function () {
    $method = new ReflectionMethod(OperationsPulse::class, 'deltaPct');
    $method->setAccessible(true);
    $pulse = new OperationsPulse();

    expect($method->invoke($pulse, 5, 0))->toBe(100.0)
        ->and($method->invoke($pulse, 0, 0))->toBeNull()
        ->and($method->invoke($pulse, 15, 10))->toBe(50.0)
        ->and($method->invoke($pulse, 5, 10))->toBe(-50.0);
});
