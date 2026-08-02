<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelReportController;
use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiFuelReportControllerProbe extends FuelReportController
{
    public ?Driver $driver           = null;
    public ?FuelReport $fuelReport   = null;
    public array $createdFuelReports = [];
    public mixed $queryResults       = null;
    public bool $driverNotFound      = false;
    public bool $fuelReportNotFound  = false;

    protected function findDriverRecord(string $id): Driver
    {
        if ($this->driverNotFound) {
            throw new ModelNotFoundException();
        }

        $this->driver?->setAttribute('lookup_id', $id);

        return $this->driver;
    }

    protected function createFuelReport(array $input): FuelReport
    {
        $this->createdFuelReports[] = $input;

        $fuelReport = new FleetOpsApiFuelReportFake();
        $fuelReport->setRawAttributes(array_merge(['uuid' => 'created-fuel-report-uuid'], $input));

        return $fuelReport;
    }

    protected function findFuelReportRecord(string $id): FuelReport
    {
        if ($this->fuelReportNotFound) {
            throw new ModelNotFoundException();
        }

        $this->fuelReport?->setAttribute('lookup_id', $id);

        return $this->fuelReport;
    }

    protected function queryFuelReports(Request $request)
    {
        $this->queryResults = $this->queryResults ?? [['uuid' => 'fuel-report-uuid']];

        return $this->queryResults;
    }

    protected function fuelReportResource(FuelReport $fuelReport)
    {
        return ['resource' => 'fuel-report', 'fuel_report' => $fuelReport];
    }

    protected function fuelReportResourceCollection($results)
    {
        return ['collection' => 'fuel-report', 'items' => $results];
    }

    protected function deletedFuelReportResource(FuelReport $fuelReport)
    {
        return ['resource' => 'deleted-fuel-report', 'fuel_report' => $fuelReport];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiFuelReportFake extends FuelReport
{
    public array $updates       = [];
    public bool $deletedForTest = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsApiFuelReportDriver(): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
        'vehicle_uuid' => 'vehicle-uuid',
    ]);

    return $driver;
}

function fleetopsCreateFuelReportRequest(array $input): CreateFuelReportRequest
{
    return CreateFuelReportRequest::create('/api/v1/fuel-reports', 'POST', $input);
}

function fleetopsUpdateFuelReportRequest(array $input): UpdateFuelReportRequest
{
    return UpdateFuelReportRequest::create('/api/v1/fuel-reports/fuel-report-public', 'PUT', $input);
}

test('api fuel report controller creates reports from reporting driver context', function () {
    $controller         = new FleetOpsApiFuelReportControllerProbe();
    $controller->driver = fleetopsApiFuelReportDriver();

    $response = $controller->create(fleetopsCreateFuelReportRequest([
        'driver'      => 'driver-public',
        'location'    => ['latitude' => 1.3, 'longitude' => 103.8],
        'odometer'    => 12345,
        'volume'      => 42.5,
        'metric_unit' => 'L',
        'amount'      => 125.75,
        'currency'    => 'SGD',
        'status'      => 'submitted',
        'ignored'     => 'not copied',
    ]));

    expect($response['resource'])->toBe('fuel-report')
        ->and($controller->createdFuelReports)->toHaveCount(1)
        ->and($controller->createdFuelReports[0])->toMatchArray([
            'location'         => ['latitude' => 1.3, 'longitude' => 103.8],
            'odometer'         => 12345,
            'volume'           => 42.5,
            'metric_unit'      => 'L',
            'amount'           => 125.75,
            'currency'         => 'SGD',
            'status'           => 'submitted',
            'company_uuid'     => 'company-uuid',
            'driver_uuid'      => 'driver-uuid',
            'reported_by_uuid' => 'user-uuid',
            'vehicle_uuid'     => 'vehicle-uuid',
        ])
        ->and($controller->createdFuelReports[0])->not->toHaveKey('driver')
        ->and($controller->createdFuelReports[0])->not->toHaveKey('ignored');
});

test('api fuel report controller returns driver missing response during create', function () {
    $controller                 = new FleetOpsApiFuelReportControllerProbe();
    $controller->driverNotFound = true;

    expect($controller->create(fleetopsCreateFuelReportRequest(['driver' => 'missing-driver'])))->toBe([
        'json'   => ['error' => 'Driver reporting fuel report not found.'],
        'status' => 404,
    ]);
});

test('api fuel report controller updates finds queries and deletes reports', function () {
    $fuelReport = new FleetOpsApiFuelReportFake();
    $fuelReport->setRawAttributes(['uuid' => 'fuel-report-uuid', 'status' => 'submitted']);

    $controller               = new FleetOpsApiFuelReportControllerProbe();
    $controller->fuelReport   = $fuelReport;
    $controller->queryResults = [['uuid' => 'fuel-report-a'], ['uuid' => 'fuel-report-b']];

    $updated = $controller->update('fuel-report-public', fleetopsUpdateFuelReportRequest([
        'odometer'    => 13000,
        'volume'      => 44,
        'metric_unit' => 'L',
        'amount'      => 132.45,
        'currency'    => 'SGD',
        'status'      => 'approved',
        'location'    => 'ignored-location',
    ]));
    $found   = $controller->find('fuel-report-public');
    $query   = $controller->query(new Request(['limit' => 2]));
    $deleted = $controller->delete('fuel-report-public');

    expect($updated['resource'])->toBe('fuel-report')
        ->and($fuelReport->updates[0])->toBe([
            'odometer'    => 13000,
            'volume'      => 44,
            'metric_unit' => 'L',
            'amount'      => 132.45,
            'currency'    => 'SGD',
            'status'      => 'approved',
        ])
        ->and($found)->toBe(['resource' => 'fuel-report', 'fuel_report' => $fuelReport])
        ->and($query)->toBe(['collection' => 'fuel-report', 'items' => [['uuid' => 'fuel-report-a'], ['uuid' => 'fuel-report-b']]])
        ->and($deleted)->toBe(['resource' => 'deleted-fuel-report', 'fuel_report' => $fuelReport])
        ->and($fuelReport->deletedForTest)->toBeTrue();
});

test('api fuel report controller returns missing report responses for update find and delete', function () {
    $controller                     = new FleetOpsApiFuelReportControllerProbe();
    $controller->fuelReportNotFound = true;

    $expected = [
        'json'   => ['error' => 'FuelReport resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-fuel-report', fleetopsUpdateFuelReportRequest(['odometer' => 1, 'volume' => 2])))->toBe($expected)
        ->and($controller->find('missing-fuel-report'))->toBe($expected)
        ->and($controller->delete('missing-fuel-report'))->toBe($expected);
});
