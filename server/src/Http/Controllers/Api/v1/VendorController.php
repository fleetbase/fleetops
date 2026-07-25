<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateVendorRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVendorRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vendor as VendorResource;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    /**
     * Creates a new Fleetbase Vendor resource.
     *
     * @param \Fleetbase\Http\Requests\CreateVendorRequest $request
     *
     * @return \Fleetbase\Http\Resources\Vendor
     */
    public function create(CreateVendorRequest $request)
    {
        // get request input
        $input = $request->only(['name', 'type', 'email', 'phone', 'meta']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // address assignment
        if ($request->has('address')) {
            $input['place_uuid'] = $this->getPlaceUuid(
                'places',
                [
                    'public_id'    => $request->input('address'),
                    'company_uuid' => session('company'),
                ]
            );
        }

        // create the vendor
        $vendor = $this->updateOrCreateVendor(
            [
                'company_uuid' => session('company'),
                'name'         => strtoupper($input['name']),
            ],
            $input
        );

        // response the driver resource
        return $this->vendorResource($vendor);
    }

    /**
     * Updates a Fleetbase Vendor resource.
     *
     * @param string                                       $id
     * @param \Fleetbase\Http\Requests\UpdateVendorRequest $request
     *
     * @return \Fleetbase\Http\Resources\Vendor
     */
    public function update($id, UpdateVendorRequest $request)
    {
        // find for the vendor
        try {
            $vendor = $this->findVendorRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Vendor resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['name', 'type', 'email', 'phone', 'meta']);

        // address assignment
        if ($request->has('address')) {
            $input['place_uuid'] = $this->getPlaceUuid(
                'places',
                [
                    'public_id'    => $request->input('address'),
                    'company_uuid' => session('company'),
                ]
            );
        }

        // update the vendor
        $vendor->update($input);
        $vendor->flushAttributesCache();

        // response the vendor resource
        return $this->vendorResource($vendor);
    }

    /**
     * Query for Fleetbase Vendor resources.
     *
     * @return \Fleetbase\Http\Resources\VendorCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryVendors($request);

        return $this->vendorResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Vendor resources.
     *
     * @return \Fleetbase\Http\Resources\VendorCollection
     */
    public function find($id, Request $request)
    {
        // find for the vendor
        try {
            $vendor = $this->findVendorRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Vendor resource not found.',
                ],
                404
            );
        }

        // response the vendor resource
        return $this->vendorResource($vendor);
    }

    /**
     * Deletes a Fleetbase Vendor resources.
     *
     * @return \Fleetbase\Http\Resources\VendorCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $vendor = $this->findVendorRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Vendor resource not found.',
                ],
                404
            );
        }

        // delete the vendor
        $vendor->delete();

        // response the vendor resource
        return $this->deletedVendorResource($vendor);
    }

    protected function getPlaceUuid(string $table, array $where): ?string
    {
        return Utils::getUuid($table, $where);
    }

    protected function updateOrCreateVendor(array $where, array $input): Vendor
    {
        return Vendor::updateOrCreate($where, $input);
    }

    protected function findVendorRecord(string $id): Vendor
    {
        return Vendor::findRecordOrFail($id);
    }

    protected function queryVendors(Request $request)
    {
        return Vendor::queryWithRequest($request);
    }

    protected function vendorResource(Vendor $vendor)
    {
        return new VendorResource($vendor);
    }

    protected function vendorResourceCollection($results)
    {
        return VendorResource::collection($results);
    }

    protected function deletedVendorResource(Vendor $vendor)
    {
        return new DeletedResource($vendor);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
