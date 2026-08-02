<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Resources\v1\OrderConfig as OrderConfigResource;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Public, read-only API surface for OrderConfigs.
 *
 * The internal admin console manages the full OrderConfig lifecycle through
 * `/int/v1/order-configs`. This public endpoint exposes the projected,
 * non-sensitive subset (flow activities + metadata) that consumers like a
 * customer portal need to render status filter chips, activity labels, and
 * other UI driven by the active flow.
 */
class OrderConfigController extends Controller
{
    /**
     * List the OrderConfigs for the company resolved from the API credential.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryOrderConfigs($request);

        return $this->orderConfigCollection($results);
    }

    /**
     * Find a single OrderConfig. The `{id}` segment accepts any identifier
     * supported by {@see OrderConfig::resolveFromIdentifier} — `uuid`,
     * `public_id`, `namespace`, or short `key` (e.g. `transport`).
     *
     * @return OrderConfigResource|\Illuminate\Http\JsonResponse
     */
    public function find(string $id)
    {
        $orderConfig = $this->resolveOrderConfig($id);
        if (!$orderConfig) {
            try {
                $orderConfig = $this->findOrderConfigOrFail($id);
            } catch (ModelNotFoundException $e) {
                return $this->apiError('Order config not found.', 404);
            }
        }

        return $this->orderConfigResource($orderConfig);
    }

    protected function queryOrderConfigs(Request $request)
    {
        return OrderConfig::queryWithRequest($request);
    }

    protected function resolveOrderConfig(string $id): ?OrderConfig
    {
        return OrderConfig::resolveFromIdentifier($id);
    }

    protected function findOrderConfigOrFail(string $id): OrderConfig
    {
        return OrderConfig::findRecordOrFail($id);
    }

    protected function orderConfigCollection($results)
    {
        return OrderConfigResource::collection($results);
    }

    protected function orderConfigResource(OrderConfig $orderConfig)
    {
        return new OrderConfigResource($orderConfig);
    }

    protected function apiError(string $message, int $status)
    {
        return response()->apiError($message, $status);
    }
}
