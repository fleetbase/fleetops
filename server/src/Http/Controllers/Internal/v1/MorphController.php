<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Http\Filter\VendorFilter;
use Fleetbase\FleetOps\Http\Resources\v1\Contact as ContactResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vendor as VendorResource;
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
        $query          = $request->input('query');
        $limit          = $request->input('limit', 12);
        $page           = $request->input('page', 1);
        $single         = $request->boolean('single');
        $type           = Str::lower($request->segment(4));
        $resourceType   = Str::lower(Utils::singularize($type));

        $contactsQuery = $this->newContactQuery()
            ->searchWhere('name', $query)
            ->where('type', $resourceType === 'customer' ? '=' : '!=', 'customer')
            ->where('company_uuid', session('company'))
            ->applyDirectivesForPermissions('fleet-ops list contact')
            ->filter(new ContactFilter($request));

        $vendorsQuery = $this->newVendorQuery()
            ->searchWhere('name', $query)
            ->where('company_uuid', session('company'))
            ->applyDirectivesForPermissions('fleet-ops list vendor')
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
            $integratedVendors = $this->newIntegratedVendorQuery(session('company'))->get();

            if ($integratedVendors->count()) {
                $integratedVendors->each(
                    function ($integratedVendor) use ($results) {
                        $integratedVendor->setAttribute('facilitator_type', 'integrated-vendor');
                        $results->prepend($integratedVendor);
                    }
                );
            }
        }

        // if requesting single resource
        if ($single === true) {
            return $this->jsonResponse($results->first());
        }

        // set resource type
        $results = $results->map(
            function ($item) use ($resourceType) {
                $item['type'] = $resourceType;

                return $item;
            }
        );

        // Create a LengthAwarePaginator instance
        $results = $this->newLengthAwarePaginator(
            $results->forPage($page, $limit),
            $total,
            $limit,
            $page,
            ['path' => $this->currentUrl()]
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

        return $this->jsonResponse($response);
    }

    public function queryCustomers(Request $request)
    {
        $query           = $request->input('query');
        $limit           = $request->input('limit', 12);
        $single          = $request->boolean('single');
        $columns         = $this->arrayInput($request, 'columns');
        $type            = $request->input('type', 'contact');

        if ($type === 'vendor') {
            $builder = $this->newVendorQuery()
                ->searchWhere('name', $query)
                ->where(['type' => 'customer', 'company_uuid' => session('company')])
                ->applyDirectivesForPermissions('fleet-ops list vendor')
                ->filter(new VendorFilter($request));
        } else {
            $builder = $this->newContactQuery()
                ->where(['type' => 'customer', 'company_uuid' => session('company')])
                ->applyDirectivesForPermissions('fleet-ops list contact')
                ->filter(new ContactFilter($request));

            if ($request->has('user_uuid') || $request->has('user')) {
                $userId = $this->firstInput($request, ['user_uuid', 'user']);
                if ($userId) {
                    $builder->where('user_uuid', $userId);
                }
            }

            if ($query) {
                $builder->searchWhere('name', $query);
            }
        }

        // Get paginated items
        $results = $builder->fastPaginate($limit, $columns);
        $results->setCollection($results->getCollection()->map(function ($customer) use ($type) {
            $customer->customer_type = $type === 'vendor' ? 'vendor' : 'contact';

            return $customer;
        }));

        if ($single) {
            return $type === 'vendor' ? $this->vendorResource($results->first()) : $this->contactResource($results->first());
        }

        return $type === 'vendor' ? $this->vendorResourceCollection($results) : $this->contactResourceCollection($results);
    }

    public function queryFacilitators(Request $request)
    {
        $query           = $request->input('query');
        $limit           = $request->input('limit', 12);
        $single          = $request->boolean('single');
        $columns         = $this->arrayInput($request, 'columns');
        $type            = $request->input('type', 'vendor');

        if ($type === 'contact') {
            $builder = $this->newContactQuery()
                ->searchWhere('name', $query)
                ->where(['type' => 'facilitator', 'company_uuid' => session('company')])
                ->applyDirectivesForPermissions('fleet-ops list contact')
                ->filter(new ContactFilter($request));
        } else {
            $builder = $this->newVendorQuery()
                ->searchWhere('name', $query)
                ->where(['type' => 'facilitator', 'company_uuid' => session('company')])
                ->applyDirectivesForPermissions('fleet-ops list vendor')
                ->filter(new VendorFilter($request));
        }

        // Get paginated items
        $results = $builder->fastPaginate($limit, $columns);
        $results->setCollection($results->getCollection()->map(function ($facilitator) use ($type) {
            $facilitator->facilitator_type = $type === 'contact' ? 'contact' : 'vendor';

            return $facilitator;
        }));

        if ($single) {
            return $type === 'contact' ? $this->contactResource($results->first()) : $this->vendorResource($results->first());
        }

        return $type === 'contact' ? $this->contactResourceCollection($results) : $this->vendorResourceCollection($results);
    }

    protected function newContactQuery()
    {
        return Contact::select('*');
    }

    protected function newVendorQuery()
    {
        return Vendor::select('*');
    }

    protected function newIntegratedVendorQuery(string $companyUuid)
    {
        return IntegratedVendor::where('company_uuid', $companyUuid);
    }

    protected function newLengthAwarePaginator($items, int $total, int $limit, int $page, array $options)
    {
        return new LengthAwarePaginator($items, $total, $limit, $page, $options);
    }

    protected function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    protected function firstInput(Request $request, array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($request->filled($key)) {
                return $request->input($key);
            }
        }

        return null;
    }

    protected function currentUrl(): string
    {
        return URL::current();
    }

    protected function jsonResponse(mixed $data)
    {
        return response()->json($data);
    }

    protected function contactResource($resource)
    {
        return new ContactResource($resource);
    }

    protected function vendorResource($resource)
    {
        return new VendorResource($resource);
    }

    protected function contactResourceCollection($resource)
    {
        return ContactResource::collection($resource);
    }

    protected function vendorResourceCollection($resource)
    {
        return VendorResource::collection($resource);
    }
}
