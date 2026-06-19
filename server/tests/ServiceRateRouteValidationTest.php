<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceRateController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

function fleetopsServiceRateRouteRequest(array $query = []): Request
{
    $request = Request::create('/int/v1/service-rates/for-route', 'GET', $query);
    $session = app('session.store');
    $session->put('company', 'company_test');
    $request->setLaravelSession($session);

    return $request;
}

function fleetopsServiceRateRouteController()
{
    return new class extends ServiceRateController {
        public ?Collection $waypoints  = null;
        public ?Closure $queryCallback = null;

        protected function getServicableForWaypoints(Collection $waypoints, Closure $queryCallback): array
        {
            $this->waypoints     = $waypoints;
            $this->queryCallback = $queryCallback;

            return [['id' => 'service_rate_test']];
        }
    };
}

test('service rates for route requires coordinates', function ($query) {
    $response = fleetopsServiceRateRouteController()->getServicesForRoute(fleetopsServiceRateRouteRequest($query));

    expect($response->getStatusCode())->toBe(422);
})->with([
    'missing coordinates' => [[]],
    'empty coordinates'   => [['coordinates' => '']],
]);

test('service rates for route requires at least two coordinates', function () {
    $response = fleetopsServiceRateRouteController()->getServicesForRoute(fleetopsServiceRateRouteRequest([
        'coordinates' => '1.3621663,103.8845049',
    ]));

    expect($response->getStatusCode())->toBe(422);
});

test('service rates for route rejects malformed coordinates', function ($coordinates) {
    $response = fleetopsServiceRateRouteController()->getServicesForRoute(fleetopsServiceRateRouteRequest([
        'coordinates' => $coordinates,
    ]));

    expect($response->getStatusCode())->toBe(422);
})->with([
    'missing longitude' => ['1.3621663;1.353151,103.86458'],
    'missing latitude'  => [',103.8845049;1.353151,103.86458'],
    'blank segment'     => ['1.3621663,103.8845049;;1.353151,103.86458'],
    'non numeric'       => ['north,103.8845049;1.353151,103.86458'],
]);

test('service rates for route converts coordinate pairs to waypoints', function () {
    $controller = fleetopsServiceRateRouteController();
    $response   = $controller->getServicesForRoute(fleetopsServiceRateRouteRequest([
        'coordinates'  => '1.3621663,103.8845049;1.353151,103.86458',
        'service_type' => 'delivery',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($controller->waypoints)->toHaveCount(2)
        ->and($controller->waypoints->first()->x())->toBe(103.8845049)
        ->and($controller->waypoints->first()->y())->toBe(1.3621663);
});

test('service rates for route ignores placeholder service type values', function ($serviceType) {
    $controller = fleetopsServiceRateRouteController();
    $controller->getServicesForRoute(fleetopsServiceRateRouteRequest([
        'coordinates'  => '1.3621663,103.8845049;1.353151,103.86458',
        'service_type' => $serviceType,
    ]));

    $query = new class {
        public array $wheres = [];

        public function where($column, $value)
        {
            $this->wheres[] = [$column, $value];

            return $this;
        }
    };

    ($controller->queryCallback)($query);

    expect($query->wheres)->toBe([['company_uuid', 'company_test']]);
})->with([
    'undefined' => ['undefined'],
    'null'      => ['null'],
    'empty'     => [''],
]);
