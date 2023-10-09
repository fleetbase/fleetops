<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MorphController extends Controller
{
    /**
     * Search facilitators or customers which is a comibined query on contacts or vendor resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function queryCustomersOrFacilitators(Request $request)
    {
        $query        = $request->input('query');
        $limit        = $request->input('limit', 12);
        $type         = Str::lower($request->segment(4));
        $resourceType = Str::lower(Utils::singularize($type));

        $contacts = Contact::searchWhere('name', $query)
            ->where('company_uuid', session('company'))
            ->limit($limit)
            ->get();

        $vendors = Vendor::searchWhere('name', $query)
            ->where('company_uuid', session('company'))
            ->limit($limit)
            ->get();

        $results = collect([...$contacts, ...$vendors])
            ->sortBy('name')
            ->map(
                function ($resource) use ($type) {
                    $resource->setAttribute(Utils::singularize($type) . '_type', Str::lower(Utils::classBasename($resource)));

                    return $resource->toArray();
                }
            )
            ->values();

        // insert integrated vendors if user has any
        if ($resourceType === 'facilitator') {
            $integratedVendors = IntegratedVendor::where('company_uuid', session('company'))->get();

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
}
