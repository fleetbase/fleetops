<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\PlaceExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Imports\PlaceImport;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class PlaceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'place';

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Place $place)
    {
        $customFieldValues = $request->array('place.custom_field_values');
        if ($customFieldValues) {
            $place->syncCustomFieldValues($customFieldValues);
        }
    }

    /**
     * Quick search places for selection.
     *
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $searchQuery = $request->searchQuery();
        $limit       = $request->input('limit', 30);
        $geo         = $request->boolean('geo');
        $latitude    = $request->input('latitude');
        $longitude   = $request->input('longitude');

        $query = Place::where('company_uuid', session('company'))
            ->whereNull('deleted_at')
            ->applyDirectivesForPermissions('fleet-ops list place');

        $results = PlaceSearch::search($query, $searchQuery, [
            'limit'          => $limit,
            'geo'            => $geo,
            'latitude'       => $latitude,
            'longitude'      => $longitude,
            'no_query_order' => 'name_desc',
        ]);

        return PlaceResource::collection($results);
    }

    /**
     * Search using geocoder for addresses.
     *
     * @return \Illuminate\Http\Response
     */
    public function geocode(ExportRequest $request)
    {
        $searchQuery = $request->searchQuery();
        $latitude    = $request->input('latitude', false);
        $longitude   = $request->input('longitude', false);
        $results     = PlaceSearch::geocode($searchQuery, $latitude, $longitude);

        return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * Export the places to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('places-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new PlaceExport($selections), $fileName);
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = Place::getAvatarOptions();

        return response()->json($options);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = new PlaceImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }
}
