<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\ContactExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\Vendor as VendorResource;
use Fleetbase\FleetOps\Imports\ContactImport;
use Fleetbase\FleetOps\Mail\CustomerCredentialsMail;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $this->assertCustomerPortalCanSendWelcomeEmail($input);
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
            $this->sendCustomerPortalWelcomeEmail($contact);
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

    public function convertToVendor(Request $request, string $id)
    {
        $contact = Contact::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('id', $id);
            })
            ->firstOrFail();

        $vendor = DB::transaction(function () use ($contact, $request) {
            $originalType = $contact->type;
            $vendor       = Vendor::create([
                'company_uuid' => $contact->company_uuid,
                'place_uuid'   => $contact->place_uuid,
                'name'         => $request->input('name', $contact->name),
                'email'        => $request->input('email', $contact->email),
                'phone'        => $request->input('phone', $contact->phone),
                'status'       => 'active',
                'type'         => 'customer',
                'meta'         => [
                    'converted_from_contact_uuid' => $contact->uuid,
                    'converted_from_contact_type' => $originalType,
                    'converted_by_uuid'           => session('user'),
                    'converted_at'                => now()->toISOString(),
                ],
            ]);

            VendorPersonnel::updateOrCreate(
                ['vendor_uuid' => $vendor->uuid, 'contact_uuid' => $contact->uuid],
                ['role' => 'admin', 'status' => 'active', 'invited_by_uuid' => session('user')]
            );

            $this->migrateContactCustomerContextToVendor($contact, $vendor);

            $contact->update([
                'type' => 'customer',
                'meta' => array_merge((array) $contact->meta, [
                    'converted_from_type'            => $originalType,
                    'converted_to_vendor_uuid'       => $vendor->uuid,
                    'converted_to_vendor_public_id'  => $vendor->public_id,
                    'converted_to_vendor_at'         => now()->toISOString(),
                ]),
            ]);

            return $vendor;
        });

        return response()->json([
            'vendor' => (new VendorResource($vendor->load('personnels')))->resolve(),
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

    private function migrateContactCustomerContextToVendor(Contact $contact, Vendor $vendor): void
    {
        $contactType = Utils::getMutationType($contact);
        $vendorType  = Utils::getMutationType($vendor);
        $filter      = ['customer_uuid' => $contact->uuid, 'customer_type' => $contactType];
        $replacement = ['customer_uuid' => $vendor->uuid, 'customer_type' => $vendorType];

        Order::where($filter)->update($replacement);
        PurchaseRate::where($filter)->update($replacement);
        Entity::where($filter)->update($replacement);

        Issue::where('company_uuid', $contact->company_uuid)
            ->where('meta->customer_portal->customer_uuid', $contact->uuid)
            ->where('meta->customer_portal->customer_type', 'contact')
            ->get()
            ->each(function (Issue $issue) use ($vendor) {
                $meta = (array) $issue->meta;
                data_set($meta, 'customer_portal.customer_uuid', $vendor->uuid);
                data_set($meta, 'customer_portal.customer_type', 'vendor');
                $issue->update(['meta' => $meta]);
            });
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

    private function assertCustomerPortalCanSendWelcomeEmail(array $input): void
    {
        $type = data_get($input, 'type', 'contact');
        if ($type !== 'customer' || !data_get($input, 'meta.customer_portal.send_welcome_email')) {
            return;
        }

        if (!$this->isCustomerPortalInstalled()) {
            throw new \Exception('Customer portal must be installed before sending a customer welcome email.');
        }
    }

    private function sendCustomerPortalWelcomeEmail(Contact $contact): void
    {
        if (!data_get($contact->meta, 'customer_portal.send_welcome_email')) {
            return;
        }

        if (!$this->isCustomerPortalInstalled()) {
            throw new \Exception('Customer portal must be installed before sending a customer welcome email.');
        }

        $user = $contact->getUser() ?? Contact::createUserFromContact($contact, false, true);
        if (!$user) {
            throw new \Exception('Unable to create customer portal login.');
        }

        $password = Str::random(16);
        $user->changePassword($password);

        if ($user->status !== 'active') {
            $user->activate();
        }

        Mail::to($user)->send(new CustomerCredentialsMail($password, $contact));

        $meta = (array) $contact->meta;
        data_forget($meta, 'customer_portal.send_welcome_email');
        $contact->forceFill(['meta' => $meta])->saveQuietly();
        $contact->setAttribute('meta', $meta);
    }

    private function isCustomerPortalInstalled(): bool
    {
        return collect(Utils::getInstalledFleetbaseExtensions())
            ->contains(fn ($package) => data_get($package, 'name') === 'fleetbase/customer-portal-api');
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
