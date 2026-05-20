<?php

use Fleetbase\FleetOps\Support\Metrics\AbstractMetric;
use Fleetbase\FleetOps\Support\Metrics\AvgOrderValueMetric;
use Fleetbase\FleetOps\Support\Metrics\EarningsMetric;
use Fleetbase\FleetOps\Support\Metrics\OpenIssuesMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersInProgressMetric;
use Fleetbase\FleetOps\Support\Metrics\Registry;
use Fleetbase\FleetOps\Support\Metrics\ResolvedIssuesMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalTimeTraveledMetric;

test('registry exposes every known metric slug', function () {
    $slugs = Registry::slugs();

    expect($slugs)->toContain('earnings');
    expect($slugs)->toContain('fuel_costs');
    expect($slugs)->toContain('total_distance_traveled');
    expect($slugs)->toContain('total_time_traveled');
    expect($slugs)->toContain('orders_completed');
    expect($slugs)->toContain('orders_canceled');
    expect($slugs)->toContain('orders_in_progress');
    expect($slugs)->toContain('orders_scheduled');
    expect($slugs)->toContain('active_live_orders');
    expect($slugs)->toContain('drivers_online');
    expect($slugs)->toContain('total_drivers');
    expect($slugs)->toContain('total_customers');
    expect($slugs)->toContain('open_issues');
    expect($slugs)->toContain('resolved_issues');
    expect($slugs)->toContain('avg_order_value');
});

test('registry resolves unknown slugs to null', function () {
    expect(Registry::resolve('does_not_exist'))->toBeNull();
});

test('every registered metric extends the abstract base', function () {
    foreach (Registry::all() as $slug => $class) {
        expect(is_subclass_of($class, AbstractMetric::class))
            ->toBeTrue("Metric class for slug '{$slug}' must extend AbstractMetric");
        expect($class::slug())->toBe($slug);
    }
});

test('open and resolved issue metrics scope to the constructor company (not session)', function () {
    $sourceOpen = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/OpenIssuesMetric.php');
    $sourceResolved = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/ResolvedIssuesMetric.php');

    expect($sourceOpen)->not->toContain("session('company')");
    expect($sourceOpen)->toContain('$this->company->uuid');
    expect($sourceResolved)->not->toContain("session('company')");
    expect($sourceResolved)->toContain('$this->company->uuid');
});

test('total_time_traveled metric sums the time column, not distance', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/TotalTimeTraveledMetric.php');

    expect($source)->toContain("\$query->sum('time')");
    expect($source)->not->toContain("\$query->sum('distance')");
    expect(TotalTimeTraveledMetric::slug())->toBe('total_time_traveled');
});

test('money metrics return float values to preserve cents', function () {
    $earnings = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/EarningsMetric.php');
    $fuelCosts = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/FuelCostsMetric.php');

    expect($earnings)->toContain('(float) $query->sum');
    expect($earnings)->not->toMatch('/\(int\)\s*\$query->sum/');
    expect($fuelCosts)->toContain('(float) $query->sum');
    expect($fuelCosts)->not->toMatch('/\(int\)\s*\$query->sum/');
});

test('money metrics filter by currency to avoid mixed-currency sums', function () {
    $earnings = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/EarningsMetric.php');
    $fuelCosts = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/FuelCostsMetric.php');
    $aov = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/AvgOrderValueMetric.php');

    expect($earnings)->toContain("->where('currency'");
    expect($fuelCosts)->toContain("->where('currency'");
    expect($aov)->toContain("->where('currency'");
});

test('ordersInProgress uses an explicit allowlist rather than an exclusion list', function () {
    $statuses = OrdersInProgressMetric::IN_PROGRESS_STATUSES;
    expect($statuses)->toBeArray();
    expect($statuses)->not->toBeEmpty();
    expect($statuses)->toContain('dispatched');
    expect($statuses)->toContain('started');
});
