<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * One recorded route, handed back so the route file can chain onto it as it
 * would onto a real one. `middleware()` is recorded against the route; any
 * other chained call (`name()`, `where()`) is accepted and ignored, so a chain
 * the recorder does not model cannot break the whole file.
 */
class FleetOpsRecordedRoute
{
    public function __construct(private FleetOpsRouteRecorder $recorder, private int $index)
    {
    }

    public function middleware(array|string $middleware): self
    {
        $this->recorder->routes[$this->index]['middleware'] = array_merge(
            $this->recorder->routes[$this->index]['middleware'] ?? [],
            (array) $middleware,
        );

        return $this;
    }

    public function __call(string $method, array $arguments): self
    {
        return $this;
    }
}

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

    public function get(string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record('GET', $uri, $action);
    }

    public function post(string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record('POST', $uri, $action);
    }

    public function put(string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record('PUT', $uri, $action);
    }

    public function patch(string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record('PATCH', $uri, $action);
    }

    public function delete(string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record('DELETE', $uri, $action);
    }

    public function any(string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record('ANY', $uri, $action);
    }

    public function match(array $methods, string $uri, string|array $action): FleetOpsRecordedRoute
    {
        return $this->record(implode('|', array_map('strtoupper', $methods)), $uri, $action);
    }

    /**
     * The platform's resource routes, and the extra routes a resource declares
     * in its callback. The callback is run inside a group prefixed with the
     * resource, as the platform's macro does, and handed a `$controller` that
     * names the action the way the macro would, so those routes are recorded
     * rather than silently skipped.
     */
    public function fleetbaseRoutes(string $resource, ?callable $callback = null): FleetOpsRecordedRoute
    {
        $route = $this->record('FLEETBASE', $resource, 'fleetbaseRoutes');

        if ($callback) {
            $controllerName = Str::studly(Str::singular($resource)) . 'Controller';
            $this->group(['prefix' => $resource], function ($router) use ($callback, $controllerName) {
                $callback($router, fn (string $method) => $controllerName . '@' . $method);
            });
        }

        return $route;
    }

    /** Router methods the recorder does not model are accepted and ignored. */
    public function __call(string $method, array $arguments): self
    {
        return $this;
    }

    private function record(string $method, string $uri, string|array $action): FleetOpsRecordedRoute
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

        return new FleetOpsRecordedRoute($this, array_key_last($this->routes));
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
        ->toContain('int/v1/fleet-ops/hubs/resources')
        ->toContain('public/inspections/forms/{id}')
        ->toContain('public/inspections/forms/{id}/submit')
        ->toContain('public/inspections/forms/{id}/files');

    // A link's PIN can be sent again from the console.
    $sendPin = array_values(array_filter($recorder->routes, fn (array $route) => str_ends_with($route['uri'], 'inspection-forms/{id}/links/{linkId}/send-pin')));
    expect($sendPin)->toHaveCount(1)
        ->and($sendPin[0]['method'])->toBe('POST')
        ->and($sendPin[0]['action'])->toBe('InspectionFormController@sendPin');

    // Uploads through a link have a tighter limit of their own, on top of the group's.
    $upload = collect($recorder->routes)->firstWhere('uri', 'public/inspections/forms/{id}/files');
    expect($upload['middleware'] ?? [])->toBe(['throttle:20,1,inspection-upload']);
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

    // The public inspection routes answer in JSON whatever the client asks
    // for, and are rate limited per address.
    expect($recorder->groups)->toContainEqual([
        'prefix'     => 'public',
        'namespace'  => 'Public',
        'middleware' => [Fleetbase\FleetOps\Http\Middleware\ForceJsonResponse::class, 'throttle:60,1,inspection-public'],
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
