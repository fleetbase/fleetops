<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\ProfileAccountManager;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FleetOpsLookupController extends Controller
{
    /**
     * Describes what an email/phone entered on a driver, contact or customer
     * form resolves to: the team member the profile would be linked to, or why
     * the email/phone can't be used.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function profileIdentity(Request $request)
    {
        $type = $request->input('type', 'driver');
        if (!in_array($type, ProfileAccountManager::MANAGED_TYPES, true)) {
            return response()->error('Invalid profile type.', 422);
        }

        $currentUser = $this->currentProfileUser($type, $request->input('ignore'));
        $lookup      = ProfileAccountManager::lookup(session('company'), $type, $request->input('email'), $request->input('phone'), $currentUser);
        $staff       = $lookup['staff'];

        return response()->json([
            'staff'    => $staff ? ['uuid' => $staff->uuid, 'name' => $staff->name, 'email' => $staff->email, 'phone' => $staff->phone] : null,
            'conflict' => $lookup['conflict'],
        ]);
    }

    /**
     * The account of the profile being edited, so its own email/phone isn't
     * reported as taken.
     */
    private function currentProfileUser(string $type, ?string $id): ?User
    {
        if (!$id) {
            return null;
        }

        $model   = $type === 'driver' ? Driver::withoutGlobalScopes() : Contact::query();
        $profile = $model->where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->first();

        return $profile?->user_uuid ? User::where('uuid', $profile->user_uuid)->first() : null;
    }

    /**
     * Returns a collection of polymorphic resources as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the polymorphic resources
     */
    public function polymorphs(Request $request)
    {
        $query        = $request->or(['query', 'q']);
        $limit        = $request->input('limit', 16);
        $type         = Str::lower(Arr::last($request->segments()));
        $resourceType = Str::lower(Str::singular($type));

        $contacts = $this->searchContacts($query, $limit);

        $vendors = $this->searchVendors($query, $limit);

        $results = collect([...$contacts, ...$vendors])
            ->sortBy('name')
            ->map(
                function ($resource) use ($type) {
                    $resource->setAttribute(Str::singular($type) . '_type', Str::lower(Utils::classBasename($resource)));

                    return $resource->toArray();
                }
            )
            ->values();

        // insert integrated vendors if user has any
        if ($resourceType === 'facilitator') {
            $integratedVendors = $this->integratedVendors();

            if ($integratedVendors->count()) {
                $integratedVendors->each(
                    function ($integratedVendor) use ($results) {
                        $integratedVendor->setAttribute('facilitator_type', 'integrated-vendor');
                        $results->prepend($integratedVendor);
                    }
                );
            }
        }

        // convert to array
        $results = $results->toArray();

        // set resource type
        $results = array_map(
            function ($attributes) use ($resourceType) {
                $attributes['type'] = $resourceType;

                return $attributes;
            },
            $results
        );

        return response()->json([$type => $results]);
    }

    protected function searchContacts(?string $query, int|string $limit)
    {
        return Contact::where('name', 'like', '%' . $query . '%')
            ->where('company_uuid', session('company'))
            ->limit($limit)
            ->get();
    }

    protected function searchVendors(?string $query, int|string $limit)
    {
        return Vendor::where('name', 'like', '%' . $query . '%')
            ->where('company_uuid', session('company'))
            ->limit($limit)
            ->get();
    }

    protected function integratedVendors()
    {
        return IntegratedVendor::where('company_uuid', session('company'))->get();
    }
}
