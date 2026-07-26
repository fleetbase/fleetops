<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Support\IntegratedVendors;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Illuminate\Http\Request;

class IntegratedVendorController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'integrated_vendor';

    /**
     * Get available integrated vendors.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSupported(Request $request)
    {
        $supported = $this->supportedIntegratedVendors()->map(function ($vendor) {
            return $vendor->toArray();
        });

        return response()->json($supported);
    }

    /**
     * Bulk delete resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);

        if (!$ids) {
            return $this->errorResponse('Nothing to delete.');
        }

        /** @var \Fleetbase\Models\IntegratedVendor */
        $query   = $this->integratedVendorQuery($ids);
        $count   = $query->count();
        $deleted = $query->delete();

        if (!$deleted) {
            return $this->errorResponse('Failed to bulk delete vendors.');
        }

        return $this->jsonResponse(
            [
                'status'  => 'OK',
                'message' => 'Deleted ' . $count . ' integrated vendors',
            ],
            200
        );
    }

    protected function supportedIntegratedVendors()
    {
        return IntegratedVendors::all();
    }

    protected function integratedVendorQuery(array $ids)
    {
        return IntegratedVendor::whereIn('uuid', $ids);
    }

    protected function jsonResponse(array $payload, int $status = 200)
    {
        return response()->json($payload, $status);
    }

    protected function errorResponse(string $message)
    {
        return response()->error($message);
    }
}
