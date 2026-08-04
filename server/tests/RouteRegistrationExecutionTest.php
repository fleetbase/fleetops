<?php

use Illuminate\Support\Facades\Route;

class FleetOpsRouteRecorder
{
    public array $routes   = [];
    public array $groups   = [];
    private array $pending = [];
    private array $stack   = [];

    public function prefix(?string $prefix): self
    {
        $this->pending['prefix'] = $prefix;

        return $this;
    }

    public function namespace(string $namespace): self
    {
        $this->pending['namespace'] = $namespace;

        return $this;
    }

    public function middleware(array|string $middleware): self
    {
        $this->pending['middleware'] = $middleware;

        return $this;
    }

    public function group(array|callable $attributes, ?callable $callback = null): self
    {
        if (is_callable($attributes) && $callback === null) {
            $callback   = $attributes;
            $attributes = [];
        }

        $group          = array_merge($this->pending, $attributes);
        $this->pending  = [];
        $this->groups[] = $group;
        $this->stack[]  = $group;

        $callback?->__invoke($this);

        array_pop($this->stack);

        return $this;
    }

    public function get(string $uri, string|array $action): void
    {
        $this->record('GET', $uri, $action);
    }

    public function post(string $uri, string|array $action): void
    {
        $this->record('POST', $uri, $action);
    }

    public function put(string $uri, string|array $action): void
    {
        $this->record('PUT', $uri, $action);
    }

    public function patch(string $uri, string|array $action): void
    {
        $this->record('PATCH', $uri, $action);
    }

    public function delete(string $uri, string|array $action): void
    {
        $this->record('DELETE', $uri, $action);
    }

    public function any(string $uri, string|array $action): void
    {
        $this->record('ANY', $uri, $action);
    }

    public function match(array $methods, string $uri, string|array $action): void
    {
        $this->record(implode('|', array_map('strtoupper', $methods)), $uri, $action);
    }

    public function fleetbaseRoutes(string $resource): void
    {
        $this->record('FLEETBASE', $resource, 'fleetbaseRoutes');
    }

    private function record(string $method, string $uri, string|array $action): void
    {
        $prefixes = array_values(array_filter(array_map(
            fn (array $group) => $group['prefix'] ?? null,
            $this->stack,
        ), fn ($prefix) => $prefix !== null && $prefix !== ''));

        $this->routes[] = [
            'method' => $method,
            'uri'    => implode('/', [...$prefixes, $uri]),
            'action' => $action,
            'groups' => $this->stack,
        ];
    }
}

function fleetOpsRecordedRoutes(): FleetOpsRouteRecorder
{
    $recorder = new FleetOpsRouteRecorder();
    Route::swap($recorder);

    require dirname(__DIR__) . '/src/routes.php';

    return $recorder;
}

test('fleetops route file registers public internal analytics metrics and hub routes', function () {
    $recorder = fleetOpsRecordedRoutes();
    $actions  = array_column($recorder->routes, 'action');
    $uris     = array_column($recorder->routes, 'uri');

    expect($actions)
        ->toContain('DriverController@login')
        ->toContain('CustomerController@createOrder')
        ->toContain('FuelTransactionController@matchVehicle')
        ->toContain('OrderController@dispatchOrder')
        ->toContain('AnalyticsController@operationsPulse')
        ->toContain('MetricsController@show')
        ->toContain('HubController@resources')
        ->toContain('HubController@maintenance')
        ->toContain('TelematicWebhookController@handle')
        ->toContain('TelematicWebhookController@ingest')
        ->toContain('fleetbaseRoutes');

    expect($uris)
        ->toContain('v1/drivers/login')
        ->toContain('v1/customers/orders')
        ->toContain('v1/fuel-transactions/{id}/match-vehicle')
        ->toContain('int/v1/fleet-ops/analytics/operations-pulse')
        ->toContain('int/v1/fleet-ops/metrics/{slug}')
        ->toContain('int/v1/fleet-ops/hubs/resources');
});

test('fleetops route file wires route groups with expected middleware and namespaces', function () {
    $recorder = fleetOpsRecordedRoutes();

    expect($recorder->groups)->toContainEqual([
        'prefix'    => null,
        'namespace' => 'Fleetbase\FleetOps\Http\Controllers',
    ]);

    expect($recorder->groups)->toContainEqual([
        'prefix'     => 'v1',
        'middleware' => ['fleetbase.api', Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware::class],
        'namespace'  => 'Api\v1',
    ]);

    expect($recorder->groups)->toContainEqual([
        'prefix'    => 'int',
        'namespace' => 'Internal',
    ]);

    expect($recorder->groups)->toContainEqual([
        'prefix'    => 'v1/fleet-ops',
        'namespace' => 'v1',
    ]);

    expect($recorder->groups)->toContainEqual([
        'prefix'     => 'v1',
        'namespace'  => 'v1',
        'middleware' => [
            'fleetbase.protected',
            Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware::class,
            Fleetbase\FleetOps\Http\Middleware\SetupDriverSession::class,
        ],
    ]);
});
