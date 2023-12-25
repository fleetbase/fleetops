<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Http\Filter\VendorFilter;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
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
        $page         = $request->input('page', 1);
        $type         = Str::lower($request->segment(4));
        $resourceType = Str::lower(Utils::singularize($type));

        $contactsQuery = Contact::searchWhere('name', $query)
            ->where('company_uuid', session('company'))
            ->filter(new ContactFilter($request));

        $vendorsQuery = Vendor::searchWhere('name', $query)
            ->where('company_uuid', session('company'))
            ->filter(new VendorFilter($request));

        // Get total count for pagination
        $totalContacts = $contactsQuery->count();
        $totalVendors  = $vendorsQuery->count();
        $total         = $totalContacts + $totalVendors;

        // Get paginated items
        $contacts = $contactsQuery->limit($limit)->get();
        $vendors  = $vendorsQuery->limit($limit)->get();

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

        // set resource type
        $results = $results->map(
            function ($item) use ($resourceType) {
                $item['type'] = $resourceType;

                return $item;
            }
        );

        // Create a LengthAwarePaginator instance
        $results = new LengthAwarePaginator(
            $results->forPage($page, $limit),
            $total,
            $limit,
            $page,
            ['path' => URL::current()]
        );

        // Manually structure the response
        $response = [
            $type  => $results->items(),
            'meta' => [
                'total'         => $results->total(),
                'per_page'      => $results->perPage(),
                'current_page'  => $results->currentPage(),
                'last_page'     => $results->lastPage(),
                'next_page_url' => $results->nextPageUrl(),
                'prev_page_url' => $results->previousPageUrl(),
                'from'          => $results->firstItem(),
                'to'            => $results->lastItem(),
            ],
        ];

        return response()->json($response);
    }
}
