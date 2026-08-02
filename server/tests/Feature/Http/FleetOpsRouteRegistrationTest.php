<?php

use Illuminate\Support\Facades\Route;

class FleetOpsFeatureRouteEntry
{
    public function __construct(private array &$route)
    {
    }

    public function middleware(array|string $middleware): self
    {
        $this->route['middleware'] = is_array($middleware) ? $middleware : [$middleware];

        return $this;
    }
}

class FleetOpsFeatureRouteRecorder
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
        $this->pending['middleware'] = is_array($middleware) ? $middleware : [$middleware];

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

    public function get(string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record('GET', $uri, $action);
    }

    public function post(string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record('POST', $uri, $action);
    }

    public function put(string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record('PUT', $uri, $action);
    }

    public function patch(string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record('PATCH', $uri, $action);
    }

    public function delete(string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record('DELETE', $uri, $action);
    }

    public function any(string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record('ANY', $uri, $action);
    }

    public function match(array $methods, string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        return $this->record(implode('|', array_map('strtoupper', $methods)), $uri, $action);
    }

    public function fleetbaseRoutes(string $resource, ?callable $callback = null): void
    {
        $this->record('FLEETBASE', $resource, $this->resourceController($resource, 'index'));

        $this->stack[] = ['prefix' => $resource];
        $callback?->__invoke($this, fn (string $method) => $this->resourceController($resource, $method));
        array_pop($this->stack);
    }

    private function record(string $method, string $uri, string|array $action): FleetOpsFeatureRouteEntry
    {
        $prefixes = array_values(array_filter(array_map(
            fn (array $group) => $group['prefix'] ?? null,
            $this->stack,
        ), fn ($prefix) => is_string($prefix) && $prefix !== ''));

        $this->routes[] = [
            'method' => $method,
            'uri'    => implode('/', [...$prefixes, $uri]),
            'action' => $action,
            'groups' => $this->stack,
        ];

        return new FleetOpsFeatureRouteEntry($this->routes[array_key_last($this->routes)]);
    }

    private function resourceController(string $resource, string $method): string
    {
        $controller = str($resource)->singular()->camel()->ucfirst()->append('Controller')->toString();

        return $controller . '@' . $method;
    }
}

function fleetopsFeatureRecordedRoutes(): FleetOpsFeatureRouteRecorder
{
    config()->set('fleetops.api.routing.prefix', 'fleet-ops');
    config()->set('fleetops.api.routing.internal_prefix', 'int');

    $recorder = new FleetOpsFeatureRouteRecorder();
    Route::swap($recorder);

    require dirname(__DIR__, 3) . '/src/routes.php';

    return $recorder;
}

test('fleetops internal resource route callbacks are registered with specialized endpoints', function () {
    $recorder = fleetopsFeatureRecordedRoutes();
    $routes   = collect($recorder->routes);

    expect($routes->pluck('action')->all())
        ->toContain('ContactController@convertToVendor')
        ->toContain('DriverController@scheduleItems')
        ->toContain('FleetController@assignVehicle')
        ->toContain('FuelProviderConnectionController@testCredentials')
        ->toContain('OrderController@bulkDispatch')
        ->toContain('PlaceController@geocode')
        ->toContain('TelematicController@testConnection')
        ->toContain('MaintenanceScheduleController@calendarFeed')
        ->toContain('MaintenanceController@addLineItem')
        ->toContain('PartController@import');

    expect($routes->pluck('uri')->all())
        ->toContain('fleet-ops/int/v1/drivers/{id}/schedule-items')
        ->toContain('fleet-ops/int/v1/orders/bulk-dispatch')
        ->toContain('fleet-ops/int/v1/places/lookup')
        ->toContain('fleet-ops/int/v1/telematics/{id}/devices')
        ->toContain('fleet-ops/int/v1/maintenance-schedules/calendar-feed')
        ->toContain('fleet-ops/int/v1/maintenances/{id}/line-items');
});

test('fleetops compatibility routes are registered when public prefix is configured', function () {
    $recorder = fleetopsFeatureRecordedRoutes();
    $routes   = collect($recorder->routes);

    expect($routes->pluck('action')->all())
        ->toContain('IssueController@timeline')
        ->toContain('VendorController@vendorPersonnels')
        ->toContain('VendorController@addVendorPersonnel')
        ->toContain('VendorController@removeVendorPersonnel')
        ->toContain('ContactController@convertToVendor');

    expect($routes->pluck('uri')->all())
        ->toContain('int/v1/issues/{id}/timeline')
        ->toContain('int/v1/vendors/{id}/personnels')
        ->toContain('int/v1/vendors/{id}/personnels/{contact}')
        ->toContain('int/v1/contacts/{id}/convert-to-vendor');
});
