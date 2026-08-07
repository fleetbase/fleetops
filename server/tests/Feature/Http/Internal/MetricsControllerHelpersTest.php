<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MetricsController;
use Fleetbase\FleetOps\Support\Metrics;
use Fleetbase\FleetOps\Support\Metrics\Registry;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Covers the MetricsController protected helpers: metric container and
 * per-class construction, registry slug resolution, and period parsing
 * shorthand branches.
 */
function fleetopsMetricsHelpersCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-metrics-1', 'public_id' => 'company_metrics', 'name' => 'Metrics Co'], true);

    return $company;
}

test('metrics helpers construct metric containers and resolve periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));

    $controller = new MetricsController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(MetricsController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    $company = fleetopsMetricsHelpersCompany();

    // The container and per-metric factories construct without querying
    expect($helper('metricsForCompany', $company, Carbon::now()->subDays(7)->toDateTime(), Carbon::now()->toDateTime()))->toBeInstanceOf(Metrics::class);

    $slug = collect(Registry::slugs())->first();
    if ($slug) {
        $class = $helper('resolveMetricClass', $slug);
        expect($class)->toBeString()
            ->and($helper('metricForCompany', $class, $company))->not->toBeNull();
    }
    expect($helper('resolveMetricClass', 'not-a-real-metric'))->toBeNull();

    // Period shorthand branches
    foreach (['7d' => 7, '14d' => 14, '30d' => 30, '90d' => 90, '180d' => 180, '365d' => 365] as $period => $days) {
        [$start, $end] = $helper('resolvePeriod', Request::create('/int/v1/metrics', 'GET', ['period' => $period]));
        expect(Carbon::instance($start)->toDateString())->toBe(Carbon::parse('2026-07-20')->subDays($days)->toDateString());
    }
    [$start, $end] = $helper('resolvePeriod', Request::create('/int/v1/metrics', 'GET', ['period' => '90d']));
    expect(Carbon::instance($start)->toDateString())->toBe('2026-04-21')
        ->and(Carbon::instance($end)->toDateString())->toBe('2026-07-20');

    // Unknown shorthand falls through to explicit dates and defaults
    [$start, $end] = $helper('resolvePeriod', Request::create('/int/v1/metrics', 'GET', ['period' => 'quarterly', 'start' => '2026-06-01', 'end' => '2026-06-15']));
    expect(Carbon::instance($start)->toDateString())->toBe('2026-06-01')
        ->and(Carbon::instance($end)->toDateString())->toBe('2026-06-15');

    [$start, $end] = $helper('resolvePeriod', Request::create('/int/v1/metrics', 'GET'));
    expect(Carbon::instance($start)->toDateString())->toBe('2026-06-20')
        ->and(Carbon::instance($end)->toDateString())->toBe('2026-07-20');

    Carbon::setTestNow();
});
