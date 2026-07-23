<?php

use Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics;
use Fleetbase\FleetOps\Support\Analytics\OnTimeDelivery;
use Fleetbase\FleetOps\Support\Analytics\OperationsPulse;
use Fleetbase\FleetOps\Support\Analytics\RevenueTrend;
use Fleetbase\FleetOps\Support\Analytics\TopDrivers;
use Fleetbase\Models\Company;
use Illuminate\Support\Carbon;

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

test('top drivers on-time sorting uses a sql aggregate expression', function () {
    $widget = file_get_contents(dirname(__DIR__) . '/src/Support/Analytics/TopDrivers.php');

    expect($widget)
        ->toContain("'on_time'")
        ->toContain('CASE')
        ->toContain('TIMESTAMPDIFF(SECOND, orders.scheduled_at, orders.updated_at) <= 1800')
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
