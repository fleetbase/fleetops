<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateFuelReportRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFuelReportRequest;
use Fleetbase\FleetOps\Http\Resources\v1\FuelReport as DeletedFuelReport;
use Fleetbase\FleetOps\Http\Resources\v1\FuelReport as FuelReportResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FuelReportController extends Controller
{
    /**
     * Creates a new Fleetbase FuelReport resource.
     *
     * @param \Fleetbase\Http\Requests\CreateFuelReportRequest $request
     *
     * @return \Fleetbase\Http\Resources\Entity
     */
    public function create(CreateFuelReportRequest $request)
    {
        // get request input
        $input = $request->only([
            'location',
            'odometer',
            'volume',
            'metric_unit',
            'amount',
            'currency',
            'status',
        ]);

        // Find driver who is reporting
        try {
            $driver = $this->findDriverRecord($request->input('driver'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Driver reporting fuel report not found.',
                ],
                404
            );
        }

        // get the user uuid
        $input['company_uuid']      = $driver->company_uuid;
        $input['driver_uuid']       = $driver->uuid;
        $input['reported_by_uuid']  = $driver->user_uuid;
        $input['vehicle_uuid']      = $driver->vehicle_uuid;
        $input                      = array_merge($input, $this->driverDetails($request));

        // create the fuel report
        $fuelReport = $this->createFuelReport($input);

        // response the driver resource
        return $this->fuelReportResource($fuelReport->load('files'));
    }

    /**
     * Updates new Fleetbase FuelReport resource.
     *
     * @param string                                           $id
     * @param \Fleetbase\Http\Requests\UpdateFuelReportRequest $request
     *
     * @return \Fleetbase\Http\Resources\FuelReport
     */
    public function update($id, UpdateFuelReportRequest $request)
    {
        // find for the fuel report
        try {
            $fuelReport = $this->findFuelReportRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'FuelReport resource not found.',
                ],
                404
            );
        }

        $input = $request->only([
            'odometer',
            'volume',
            'metric_unit',
            'amount',
            'currency',
            'status',
        ]);

        // update the fuel report
        $fuelReport->update(array_merge($input, $this->driverDetails($request, $fuelReport)));

        // response the fuel report resource
        return $this->fuelReportResource($fuelReport->load('files'));
    }

    /**
     * Query for Fleetbase FuelReport resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryFuelReports($request);

        // One query for every report's receipts, so a list can show which
        // fills have one without asking report by report.
        if ($results instanceof \Illuminate\Database\Eloquent\Collection) {
            $results->load('files');
        }

        return $this->fuelReportResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase FuelReport resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function find($id)
    {
        // find for the fuel report
        try {
            $fuelReport = $this->findFuelReportRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'FuelReport resource not found.',
                ],
                404
            );
        }

        // response the fuel report resource
        return $this->fuelReportResource($fuelReport->load('files'));
    }

    /**
     * Deletes a Fleetbase FuelReport resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $fuelReport = $this->findFuelReportRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'FuelReport resource not found.',
                ],
                404
            );
        }

        // delete the fuel report
        $fuelReport->delete();

        // response the fuel report resource
        return $this->deletedFuelReportResource($fuelReport);
    }

    /**
     * The station, fuel type and note a driver may write, as model attributes.
     *
     * The table has `report` for free text and nothing for the other two, so
     * station and fuel type go into `meta` beside what is already there — a
     * report matched to a fuel card keeps its `source` and `provider`. A field
     * the request does not mention is left alone; one sent empty is cleared.
     */
    protected function driverDetails(Request $request, ?FuelReport $fuelReport = null): array
    {
        $details = [];

        if ($request->exists('note')) {
            $details['report'] = filled($request->input('note')) ? trim((string) $request->input('note')) : null;
        }

        $meta    = (array) ($fuelReport?->meta ?? []);
        $changed = false;
        foreach (['station', 'fuel_type'] as $key) {
            if (!$request->exists($key)) {
                continue;
            }

            $changed = true;
            if (filled($request->input($key))) {
                $meta[$key] = trim((string) $request->input($key));
            } else {
                unset($meta[$key]);
            }
        }

        if ($changed) {
            $details['meta'] = $meta;
        }

        return $details;
    }

    protected function findDriverRecord(?string $id): Driver
    {
        return Driver::findRecordOrFail($id);
    }

    protected function createFuelReport(array $input): FuelReport
    {
        return FuelReport::create($input);
    }

    protected function findFuelReportRecord(string $id): FuelReport
    {
        return FuelReport::findRecordOrFail($id);
    }

    protected function queryFuelReports(Request $request)
    {
        return FuelReport::queryWithRequest($request);
    }

    protected function fuelReportResource(FuelReport $fuelReport)
    {
        return new FuelReportResource($fuelReport);
    }

    protected function fuelReportResourceCollection($results)
    {
        return FuelReportResource::collection($results);
    }

    protected function deletedFuelReportResource(FuelReport $fuelReport)
    {
        return new DeletedFuelReport($fuelReport);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
