<?php

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

    expect($widget)->toContain("'on_time'  => \"CASE");
    expect($widget)->toContain('TIMESTAMPDIFF(SECOND, orders.scheduled_at, orders.updated_at) <= 1800');
    expect($widget)->not->toContain("'on_time'  => 'on_time_pct'");
});
