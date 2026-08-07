<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\AnalyticsController;
use Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics;
use Fleetbase\FleetOps\Support\Analytics\OrdersByStatus;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;

/**
 * Covers the analytics controller's construction seam and its failure
 * reporting. Every widget endpoint funnels through run(), which builds the
 * widget through analyticsForCompany() and reports anything that escapes.
 */

// A real application always has this helper; the bare container the harness
// builds does not, which is the only reason the report branch is ever skipped.
if (!function_exists('report')) {
    eval('function report($exception) { $GLOBALS["fleetopsAnalyticsReported"][] = $exception; }');
}

test('analytics widgets are constructed for the requesting company', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-analytics-seam', 'name' => 'Analytics Co'], true);

    $controller = (new ReflectionClass(AnalyticsController::class))->newInstanceWithoutConstructor();

    $build = new ReflectionMethod(AnalyticsController::class, 'analyticsForCompany');
    $build->setAccessible(true);

    $analytics = $build->invoke($controller, OrdersByStatus::class, $company);

    expect($analytics)->toBeInstanceOf(OrdersByStatus::class)
        ->and($analytics)->toBeInstanceOf(AbstractAnalytics::class);
});

test('widget failures are reported and answered with a 500 naming the widget', function () {
    $GLOBALS['fleetopsAnalyticsReported'] = [];

    $controller = (new ReflectionClass(AnalyticsController::class))->newInstanceWithoutConstructor();

    $run = new ReflectionMethod(AnalyticsController::class, 'run');
    $run->setAccessible(true);

    // The request carries no authenticated user, so building the widget fails
    // inside run() — which is the path that reports and degrades to a 500
    // rather than letting the exception reach the client.
    $request  = Request::create('/int/v1/fleet-ops/analytics/orders-by-status', 'GET');
    $response = $run->invoke($controller, $request, OrdersByStatus::class);

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true)['widget'])->toBe(OrdersByStatus::class)
        ->and($GLOBALS['fleetopsAnalyticsReported'])->toHaveCount(1);
});
