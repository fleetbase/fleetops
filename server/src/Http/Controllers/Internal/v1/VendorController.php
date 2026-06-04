<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\VendorExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\Contact as ContactResource;
use Fleetbase\FleetOps\Imports\VendorImport;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;
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
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Vendor $vendor)
    {
        $customFieldValues = $request->array('vendor.custom_field_values');
        if ($customFieldValues) {
            $vendor->syncCustomFieldValues($customFieldValues);
        }
    }

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
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = new VendorImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    /**
     * Assign a driver to this vendor.
     *
     * @return \Illuminate\Http\Response
     */
    public function assignDriver(string $id, Request $request)
    {
        // Validate only param
        if (!$request->isUuid('driver')) {
            return response()->error('No driver selected to assign to vendor.');
        }

        // Find driver
        $driver = Driver::where('uuid', $request->input('driver'))->first();
        if (!$driver) {
            return response()->error('Selected driver cannot be found.');
        }

        // Validate vendor
        $vendor = Vendor::where('uuid', $id)->first();
        if (!$vendor) {
            return response()->error('Vendor attempting to assign driver to is invalid.');
        }

        // Assign driver to vendor
        $driver->update(['vendor_uuid' => $vendor->uuid]);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * Remove a driver from this vendor.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeDriver(string $id, Request $request)
    {
        // Validate only param
        if (!$request->isUuid('driver')) {
            return response()->error('No driver selected to remove from vendor.');
        }

        // Find driver
        $driver = Driver::where('uuid', $request->input('driver'))->first();
        if (!$driver) {
            return response()->error('Selected driver cannot be found.');
        }

        // Validate vendor
        $vendor = Vendor::where('uuid', $id)->first();
        if (!$vendor) {
            return response()->error('Vendor attempting to remove driver from is invalid.');
        }

        // Remove driver from vendor
        $driver->update(['vendor_uuid' => null]);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function vendorPersonnels(string $vendorId)
    {
        $vendor = Vendor::findByIdOrFail($vendorId);

        $personnels = VendorPersonnel::where('vendor_uuid', $vendor->uuid)
            ->with('contact')
            ->latest()
            ->get()
            ->map(fn (VendorPersonnel $personnel) => $this->vendorPersonnelPayload($personnel));

        return response()->json(['personnels' => $personnels->values()]);
    }

    public function addVendorPersonnel(Request $request, string $vendorId)
    {
        $vendor  = Vendor::findByIdOrFail($vendorId);
        $contact = $this->resolveOrCreatePersonnelContact($request);

        $personnel = VendorPersonnel::updateOrCreate(
            ['vendor_uuid' => $vendor->uuid, 'contact_uuid' => $contact->uuid],
            [
                'role'            => $request->input('role', 'member'),
                'status'          => $request->input('status', 'active'),
                'invited_by_uuid' => session('user'),
            ]
        );

        if ($request->boolean('create_login', true)) {
            $contact->createUser();
        }

        return response()->json([
            'personnel' => $this->vendorPersonnelPayload($personnel->load('contact')),
        ]);
    }

    public function removeVendorPersonnel(string $vendorId, string $contactId)
    {
        $vendor  = Vendor::findByIdOrFail($vendorId);
        $contact = Contact::findByIdOrFail($contactId);

        VendorPersonnel::where(['vendor_uuid' => $vendor->uuid, 'contact_uuid' => $contact->uuid])->delete();

        return response()->json(['status' => 'ok']);
    }

    protected function resolveOrCreatePersonnelContact(Request $request): Contact
    {
        $contactId = $request->input('contact');
        if ($contactId) {
            return Contact::where('company_uuid', session('company'))
                ->where(function ($query) use ($contactId) {
                    $query->where('uuid', $contactId)->orWhere('public_id', $contactId);
                })
                ->firstOrFail();
        }

        return Contact::create([
            'company_uuid' => session('company'),
            'name'         => $request->input('name'),
            'email'        => $request->input('email'),
            'phone'        => $request->input('phone'),
            'type'         => 'customer',
        ]);
    }

    protected function vendorPersonnelPayload(VendorPersonnel $personnel): array
    {
        $contact = $personnel->contact;

        return [
            'id'              => $contact?->public_id,
            'uuid'            => $contact?->uuid,
            'contact_uuid'    => $contact?->uuid,
            'public_id'       => $contact?->public_id,
            'name'            => $contact?->name,
            'email'           => $contact?->email,
            'phone'           => $contact?->phone,
            'photo_url'       => $contact?->photo_url,
            'role'            => $personnel->role ?? 'member',
            'status'          => $personnel->status ?? 'active',
            'invited_by_uuid' => $personnel->invited_by_uuid,
            'contact'         => $contact ? (new ContactResource($contact))->resolve() : null,
        ];
    }
}
