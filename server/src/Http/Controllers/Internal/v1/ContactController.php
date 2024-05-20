<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\ContactExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Support\Str;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\FleetOps\Imports\VehicleExport;
use Fleetbase\Models\File;
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
    public function import(ImportRequest $request) {
        $disk    = $request->input('disk', config('filesystems.default'));
        $files   = $request->input('files');
        $files   = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();
      
        foreach ($files as $file) {
          // validate file type
          if (!Str::endsWith($file->path, $validFileTypes)) {
              return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
          }
      
          try {
              $data = Excel::toArray(new VehicleExport(), $file->path, $disk);
          } catch (\Exception $e) {
              return response()->error('Invalid file, unable to proccess.');
          }
          
          $imports = $imports->concat($data);
        }
        
        Contact::insert($imports);
        foreach ($imports as $row) {
            Contact::insert($row);
        }
      
        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => $imports->count()]);
      }
}
