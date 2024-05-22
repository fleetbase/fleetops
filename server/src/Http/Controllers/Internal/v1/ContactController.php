<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\ContactExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\ContactImport;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ContactController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'contact';

    /**
     * Returns the contact as a `facilitator-contact`.
     *
     * @var string id
     */
    public function getAsFacilitator($id)
    {
        $contact = Contact::where('uuid', $id)->withTrashes()->first();

        if (!$contact) {
            return response()->error('Facilitator not found.');
        }

        return response()->json([
            'facilitatorContact' => $contact,
        ]);
    }

    /**
     * Returns the contact as a `customer-contact`.
     *
     * @var string id
     */
    public function getAsCustomer($id)
    {
        $contact = Contact::where('uuid', $id)->withTrashed()->first();

        if (!$contact) {
            return response()->error('Customer not found.');
        }

        return response()->json([
            'customerContact' => $contact,
        ]);
    }

    /**
     * Export the contacts to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('contacts-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new ContactExport($selections), $fileName);
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
                $data = Excel::toArray(new ContactImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to process.');
            }

            if (count($data) === 1) {
                $imports = $imports->concat($data[0]);
            }
        }

        $imports = $imports->map(function ($row) {
            // Fix phone
            if (isset($row['phone'])) {
                $row['phone'] = Utils::fixPhone($row['phone']);
            }
            // set default point for location columns if not set
            if (!isset($row['location'])) {
                $row['location'] = Utils::parsePointToWkt(new Point(0, 0));
            }

            // Assign type
            $row['type']         = 'contact';
            $row['company_uuid'] = session('company');

            return $row;
        })->values()->toArray();

        Contact::bulkInsert($imports);

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
