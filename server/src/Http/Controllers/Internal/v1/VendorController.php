<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\VendorExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\VendorImport;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class VendorController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'vendor';

    /**
     * Returns the vendor as a `facilitator-vendor`.
     *
     * @var string id
     */
    public function getAsFacilitator($id)
    {
        $vendor = Vendor::where('uuid', $id)->withTrashed()->first();

        if (!$vendor) {
            return response()->error('Facilitator not found.');
        }

        return response()->json([
            'facilitatorVendor' => $vendor,
        ]);
    }

    /**
     * Returns the vendor as a `customer-vendor`.
     *
     * @var string id
     */
    public function getAsCustomer($id)
    {
        $vendor = Vendor::where('uuid', $id)->withTrashed()->first();

        if (!$vendor) {
            return response()->error('Customer not found.');
        }

        return response()->json([
            'customerVendor' => $vendor,
        ]);
    }

    /**
     * Export the vendors to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('vendors-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new VendorExport($selections), $fileName);
    }

    /**
     * Bulk delete resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);

        if (!$ids) {
            return response()->error('Nothing to delete.');
        }

        /** @var \Fleetbase\Models\Vendor */
        $count   = Vendor::whereIn('uuid', $ids)->count();
        $deleted = Vendor::whereIn('uuid', $ids)->delete();

        if (!$deleted) {
            return response()->error('Failed to bulk delete vendors.');
        }

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Deleted ' . $count . ' vendors',
            ],
            200
        );
    }

    /**
     * Get all status options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function statuses()
    {
        $statuses = DB::table('vendors')
            ->select('status')
            ->where('company_uuid', session('company'))
            ->distinct()
            ->get()
            ->pluck('status')
            ->filter()
            ->values();

        return response()->json($statuses);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->input('files');
        $files          = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new VendorImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            if (count($data) === 1) {
                $imports = $imports->concat($data[0]);
            }
        }

        // prepare imports and fix phone
        $imports = $imports->map(
            function ($row) {
                // fix phone
                if (isset($row['phone'])) {
                    $row['phone'] = Utils::fixPhone($row['phone']);
                }

                // handle address
                if (isset($row['address'])) {
                    $place = Place::createFromMixed($row['address']);
                    if ($place) {
                        $row['place_uuid'] = $place->uuid;
                    }
                    unset($row['address']);
                }

                // handle country
                if (isset($row['country']) && is_string($row['country']) && strlen($row['country']) > 2) {
                    $row['country'] = Utils::getCountryCodeByName($row['country']);
                }

                // handle website
                if (isset($row['website'])) {
                    $row['website_url'] = $row['website'];
                    unset($row['website']);
                }

                // set default values
                $row['status'] = 'active';
                $row['type']   = 'vendor';

                return $row;
            })->values()->toArray();

        Vendor::bulkInsert($imports);

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
