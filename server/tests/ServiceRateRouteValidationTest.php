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

test('service rates route filters real service types normalizes values and exports', function () {
    // Real service types apply the service_type filter inside the callback
    $controller = fleetopsServiceRateRouteController();
    $controller->getServicesForRoute(fleetopsServiceRateRouteRequest([
        'coordinates'  => '1.3621663,103.8845049;1.353151,103.86458',
        'service_type' => 'ftl',
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
    expect($query->wheres)->toContain(['service_type', 'ftl']);

    // Non-string optional values normalize to null
    $reflection = new ReflectionMethod(ServiceRateController::class, 'normalizeOptionalQueryValue');
    $reflection->setAccessible(true);
    expect($reflection->invoke($controller, ['not-a-string']))->toBeNull()
        ->and($reflection->invoke($controller, ' keep-me '))->toBe('keep-me');

    // The real servicable-rate resolver runs its query against sqlite
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    foreach (['service_rates' => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'zone_uuid', 'service_name', 'service_type', 'base_fee', 'currency', 'rate_calculation_method', 'meta', '_key'], 'service_areas' => ['uuid', 'public_id', 'company_uuid', 'name', 'border', '_key'], 'zones' => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'border', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $realReflection = new ReflectionMethod(ServiceRateController::class, 'getServicableForWaypoints');
    $realReflection->setAccessible(true);
    $servicable = $realReflection->invoke(new ServiceRateController(), collect([]), function ($query) {
        $query->where('company_uuid', 'company_test');
    });
    expect($servicable)->toBeArray();

    // Exports stream through the excel facade
    if (!class_exists('Fleetbase\\Http\\Requests\\ExportRequest', false)) {
        eval('namespace Fleetbase\\Http\\Requests; class ExportRequest extends \\Illuminate\\Http\\Request { public function array($key, $default = []) { return (array) $this->input($key, $default); } }');
    }
    $excelFake = new class {
        public array $downloads = [];

        public function download($export, $fileName)
        {
            $this->downloads[] = [$export, $fileName];

            return 'excel-download';
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };
    app()->instance('excel', $excelFake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    $exportRequest = Fleetbase\Http\Requests\ExportRequest::create('/int/v1/service-rates/export', 'POST', ['format' => 'csv', 'selections' => ['rate-1']]);
    expect(ServiceRateController::export($exportRequest))->toBe('excel-download')
        ->and($excelFake->downloads[0][1])->toEndWith('.csv');
});
