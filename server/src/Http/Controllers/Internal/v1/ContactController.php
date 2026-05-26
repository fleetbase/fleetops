<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\ContactExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\ContactImport;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
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
     * Handle pre-create transactions.
     */
    public function onBeforeCreate(Request $request, array &$input)
    {
        $this->resolveUserInput($request, $input);
        $this->assertCustomerIdentityIsAvailable($input);
    }

    /**
     * Handle pre-update transactions.
     */
    public function onBeforeUpdate(Request $request, Contact $contact, array &$input)
    {
        if ($contact->type === 'customer' && isset($input['type']) && $input['type'] !== 'customer') {
            throw new \Exception('Customer contact type cannot be changed.');
        }

        $this->resolveUserInput($request, $input);
        $this->assertCustomerIdentityIsAvailable($input, $contact);
    }

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Contact $contact)
    {
        if ($contact->type === 'customer') {
            $contact->normalizeCustomerUser();
        }

        $customFieldValues = $request->array('contact.custom_field_values');
        if ($customFieldValues) {
            $contact->syncCustomFieldValues($customFieldValues);
        }
    }

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
        $contact = Contact::where('uuid', $id)->first();

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
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = new ContactImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    private function resolveUserInput(Request $request, array &$input): void
    {
        $user = data_get($input, 'user_uuid') ?? data_get($input, 'user') ?? $request->input('contact.user_uuid') ?? $request->input('contact.user');

        if (is_array($user)) {
            $user = data_get($user, 'uuid') ?? data_get($user, 'id');
        }

        if (!$user) {
            return;
        }

        $input['user_uuid'] = User::where('uuid', $user)->orWhere('public_id', $user)->value('uuid') ?? $user;
        unset($input['user']);
    }

    private function assertCustomerIdentityIsAvailable(array $input, ?Contact $contact = null): void
    {
        $type = data_get($input, 'type', $contact?->type);
        if ($type !== 'customer') {
            return;
        }

        $customer = $contact ? $contact->replicate() : new Contact();
        if ($contact) {
            $customer->forceFill($contact->getAttributes());
            $customer->exists = $contact->exists;
        }

        $customer->forceFill($input);
        $customer->assertCustomerIdentityIsAvailable();
    }
}
