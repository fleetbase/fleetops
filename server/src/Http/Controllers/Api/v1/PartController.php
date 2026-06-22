<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreatePartRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePartRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Part as PartResource;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Illuminate\Http\Request;

class PartController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function create(CreatePartRequest $request)
    {
        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        $part = Part::create($input)->load(['vendor', 'warranty', 'photo', 'asset']);

        return new PartResource($part);
    }

    public function update(string $id, UpdatePartRequest $request)
    {
        try {
            $part = $this->resolveModel(Part::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Part resource not found.'], 404);
        }

        $part->update($this->input($request));

        return new PartResource($part->refresh()->load(['vendor', 'warranty', 'photo', 'asset']));
    }

    public function query(Request $request)
    {
        $results = Part::queryWithRequest($request, function (&$query) {
            $query->with(['vendor', 'warranty', 'photo', 'asset']);
        });

        return PartResource::collection($results);
    }

    public function find(string $id)
    {
        try {
            $part = $this->resolveModel(Part::class, $id)->load(['vendor', 'warranty', 'photo', 'asset']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Part resource not found.'], 404);
        }

        return new PartResource($part);
    }

    public function delete(string $id)
    {
        try {
            $part = $this->resolveModel(Part::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Part resource not found.'], 404);
        }

        $part->delete();

        return new DeletedResource($part);
    }

    protected function input(Request $request): array
    {
        $input = $request->only([
            'sku',
            'name',
            'manufacturer',
            'model',
            'serial_number',
            'barcode',
            'description',
            'quantity_on_hand',
            'unit_cost',
            'msrp',
            'currency',
            'type',
            'status',
            'specs',
            'meta',
        ]);

        $this->applyPublicIdRelation($input, 'vendor', 'vendor_uuid', Vendor::class, $request);
        $this->applyPublicIdRelation($input, 'warranty', 'warranty_uuid', Warranty::class, $request);
        $this->applyPublicIdRelation($input, 'photo', 'photo_uuid', File::class, $request);

        if ($request->exists('asset')) {
            if (blank($request->input('asset'))) {
                $input['asset_type'] = null;
                $input['asset_uuid'] = null;
            } else {
                [$type, $uuid] = $this->resolveMorph($request->input('asset_type'), $request->input('asset'));
                $input['asset_type'] = $type;
                $input['asset_uuid'] = $uuid;
            }
        }

        return $input;
    }
}
