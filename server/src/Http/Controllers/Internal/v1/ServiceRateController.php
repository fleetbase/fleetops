<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Brick\Geo\Point;
use Fleetbase\FleetOps\Exports\ServiceRateExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ServiceRateController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'service_rate';

    /**
     * Creates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function getServicesForRoute(Request $request)
    {
        $coordinates = $request->input('coordinates');

        if (!is_string($coordinates) || trim($coordinates) === '') {
            return response()->error('Route coordinates are required to query service rates.', 422);
        }

        $waypoints = $this->parseRouteCoordinates($coordinates);

        if ($waypoints === null) {
            return response()->error('Invalid route coordinates provided.', 422);
        }

        if ($waypoints->count() < 2) {
            return response()->error('At least two route coordinates are required to query service rates.', 422);
        }

        $serviceType = $this->normalizeOptionalQueryValue($request->input('service_type'));

        $applicableServiceRates = $this->getServicableForWaypoints(
            $waypoints,
            function ($query) use ($request, $serviceType) {
                $query->where('company_uuid', $request->session()->get('company'));
                if ($serviceType) {
                    $query->where('service_type', $serviceType);
                }
            }
        );

        return response()->json($applicableServiceRates);
    }

    /**
     * Parse semicolon-delimited latitude,longitude coordinate pairs.
     */
    protected function parseRouteCoordinates(string $coordinates): ?Collection
    {
        $coordinates = collect(explode(';', $coordinates))
            ->map(fn ($coordinate) => trim($coordinate));

        if ($coordinates->contains('')) {
            return null;
        }

        $waypoints = $coordinates
            ->map(function ($coordinate) {
                $parts = array_map('trim', explode(',', $coordinate));

                if (count($parts) !== 2) {
                    return null;
                }

                [$latitude, $longitude] = $parts;

                if (!is_numeric($latitude) || !is_numeric($longitude)) {
                    return null;
                }

                return Point::fromText("POINT($longitude $latitude)", 4326);
            });

        if ($waypoints->contains(null)) {
            return null;
        }

        return $waypoints->values();
    }

    /**
     * Treat placeholder query-string values from the UI as absent.
     */
    protected function normalizeOptionalQueryValue($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || in_array(strtolower($value), ['null', 'undefined'], true)) {
            return null;
        }

        return $value;
    }

    /**
     * Resolve applicable service rates for route waypoints.
     */
    protected function getServicableForWaypoints(Collection $waypoints, \Closure $queryCallback): array
    {
        return ServiceRate::getServicableForWaypoints($waypoints, $queryCallback);
    }

    /**
     * Export the service rate to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('contacts-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new ServiceRateExport($selections), $fileName);
    }
}
