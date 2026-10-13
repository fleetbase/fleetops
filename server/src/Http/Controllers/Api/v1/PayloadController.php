<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Exceptions\OrderEditLockedException;
use Fleetbase\FleetOps\Http\Requests\CreatePayloadRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePayloadRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Payload as PayloadResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\OrderEdit;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

// use Fleetbase\FleetOps\Support\Utils;

class PayloadController extends Controller
{
    /**
     * Creates a new Fleetbase Payload resource.
     *
     * @param \Fleetbase\Http\Requests\CreatePayloadRequest $request
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function create(CreatePayloadRequest $request)
    {
        $input      = $request->all();
        $routeShape = $this->payloadRouteShapeFromInput($input);
        $entities   = $routeShape['entities'];
        $waypoints  = $routeShape['waypoints'];
        $pickup     = $routeShape['pickup'];
        $dropoff    = $routeShape['dropoff'];
        $return     = $routeShape['return'];

        // make sure company is set
        $input['company_uuid'] = session('company');

        // create the payload
        $payload = $this->newPayload($this->payloadFillInputFromInput($input));

        // set pickup point
        if ($pickup) {
            $payload->setPickup($pickup);
        }

        // set dropoff point
        if ($dropoff) {
            $payload->setDropoff($dropoff);
        }

        // set return point
        if ($return) {
            $payload->setReturn($return);
        }

        // save payload
        $payload->save();

        // set waypoints and entities after payload is saved
        $payload->setWaypoints($waypoints);
        $payload->setEntities($entities);

        // set the first / current waypoint
        $firstWaypoint = $payload->getPickupOrFirstWaypoint();
        if ($firstWaypoint instanceof Place) {
            $payload->setCurrentWaypoint($firstWaypoint);
        }

        // response the driver resource
        return $this->payloadResource($payload);
    }

    /**
     * Updates a Fleetbase Payload resource.
     *
     * @param string                                        $id
     * @param \Fleetbase\Http\Requests\UpdatePayloadRequest $request
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function update($id, UpdatePayloadRequest $request)
    {
        // find for the payload
        try {
            $payload = $this->findPayloadOrFail($id, ['waypoints']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Payload resource not found.',
                ],
                404
            );
        }

        // get request input
        $input                  = $request->all();
        $routeShape             = $this->payloadRouteShapeFromInput($input);
        $entities               = $routeShape['entities'];
        $waypoints              = $routeShape['waypoints'];
        $pickup                 = $routeShape['pickup'];
        $dropoff                = $routeShape['dropoff'];
        $return                 = $routeShape['return'];
        $hasWaypointsField      = $routeShape['has_waypoints_field'];
        $hasRouteEndpointFields = $routeShape['has_route_endpoint_fields'];
        $entitiesMode           = OrderEdit::entitiesMode($input['entities_mode'] ?? null);

        // when the payload belongs to an order, refuse changes the order's status locks
        $order = $this->orderForPayload($payload);
        $edit  = $order ? $this->beginOrderEdit($order) : null;
        if ($edit) {
            try {
                $edit->guard($edit->requestedChanges(Arr::only($input, ['pickup', 'dropoff', 'return', 'waypoints', 'entities', 'cod_amount']), $entitiesMode));
            } catch (OrderEditLockedException $exception) {
                return $this->lockedEditResponse($exception);
            }
        }

        // pickup assignment
        if ($pickup) {
            $payload->setPickup($pickup);
        }

        // dropoff assignment
        if ($dropoff) {
            $payload->setDropoff($dropoff);
        }

        // return assignment
        if ($return) {
            $payload->setReturn($return);
        }

        // set waypoints
        if ($hasWaypointsField && is_array($waypoints) && count($waypoints)) {
            $payload->setWaypoints($waypoints);
        } elseif ($hasWaypointsField || $hasRouteEndpointFields) {
            $payload->removeWaypoints();
        }

        // set entities: appended, or with `entities_mode=replace` the full list
        if ($entitiesMode === OrderEdit::ENTITIES_REPLACE && is_array($entities)) {
            $payload->syncEntities($entities);
        } elseif ($entities) {
            $payload->setEntities($entities);
        }

        // update the payload; empty values are skipped, but a cash-to-collect of 0 is kept
        $payload->fill($this->payloadUpdateFillInput($input));

        // save the payload
        $payload->save();

        if ($edit) {
            // the order's edit moves the current waypoint only when the route changed, keeps
            // an order under way on its stop and announces the change
            $this->commitOrderEdit($edit);
        } else {
            // set the first / current waypoint from the effective route shape
            $firstWaypoint = $payload->getPickupOrFirstWaypoint();
            if ($firstWaypoint instanceof Place) {
                $payload->setCurrentWaypoint($firstWaypoint);
            }
        }

        // make sure entities and waypoints is loaded
        $payload->load(['entities', 'waypoints', 'pickup', 'dropoff', 'return']);

        // response the payload resource
        return $this->payloadResource($payload);
    }

    /**
     * Query for Fleetbase Payload resources.
     *
     * @return \Fleetbase\Http\Resources\PayloadCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryPayloads($request);

        return $this->payloadResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Payload resources.
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function find($id, Request $request)
    {
        // find for the payload
        try {
            $payload = $this->findPayloadOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Payload resource not found.',
                ],
                404
            );
        }

        // response the payload resource
        return $this->payloadResource($payload);
    }

    /**
     * Deletes a Fleetbase Payload resources.
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $payload = $this->findPayloadOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Payload resource not found.',
                ],
                404
            );
        }

        // delete the payload
        $payload->delete();

        // response the payload resource
        return $this->deletedPayloadResource($payload);
    }

    protected function payloadRouteShapeFromInput(array $input): array
    {
        $hasPickupField    = array_key_exists('pickup', $input);
        $hasDropoffField   = array_key_exists('dropoff', $input);
        $hasReturnField    = array_key_exists('return', $input);
        $hasWaypointsField = array_key_exists('waypoints', $input);

        return [
            'entities'                  => data_get($input, 'entities', []),
            'waypoints'                 => data_get($input, 'waypoints', []),
            'pickup'                    => data_get($input, 'pickup'),
            'dropoff'                   => data_get($input, 'dropoff'),
            'return'                    => data_get($input, 'return'),
            'has_pickup_field'          => $hasPickupField,
            'has_dropoff_field'         => $hasDropoffField,
            'has_return_field'          => $hasReturnField,
            'has_waypoints_field'       => $hasWaypointsField,
            'has_route_endpoint_fields' => $hasPickupField || $hasDropoffField || $hasReturnField,
        ];
    }

    protected function payloadFillInputFromInput(array $input): array
    {
        return Arr::only($input, ['type', 'provider', 'meta', 'cod_amount', 'cod_currency', 'cod_payment_method']);
    }

    /**
     * The payload attributes an update fills: empty values are skipped as before,
     * except a numeric cash-to-collect, so 0 clears it.
     */
    protected function payloadUpdateFillInput(array $input): array
    {
        $fill = $this->payloadFillInputFromInput($input);
        $cod  = $fill['cod_amount'] ?? null;
        $fill = array_filter($fill);

        if (is_numeric($cod)) {
            $fill['cod_amount'] = $cod;
        }

        return $fill;
    }

    protected function orderForPayload(Payload $payload): ?Order
    {
        if (!$payload->uuid) {
            return null;
        }

        try {
            return Order::where('payload_uuid', $payload->uuid)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function beginOrderEdit(Order $order): OrderEdit
    {
        return OrderEdit::begin($order);
    }

    protected function commitOrderEdit(OrderEdit $edit): array
    {
        return $edit->commit();
    }

    protected function lockedEditResponse(OrderEditLockedException $exception)
    {
        return response()->apiError($exception->getMessage(), 422, $exception->toResponseData());
    }

    protected function newPayload(array $input): Payload
    {
        return new Payload($input);
    }

    protected function findPayloadOrFail(string $id, array $with = []): Payload
    {
        return Payload::findRecordOrFail($id, $with);
    }

    protected function queryPayloads(Request $request): mixed
    {
        return Payload::queryWithRequest($request);
    }

    protected function payloadResource(Payload $payload)
    {
        return new PayloadResource($payload);
    }

    protected function payloadResourceCollection(mixed $results): mixed
    {
        return PayloadResource::collection($results);
    }

    protected function deletedPayloadResource(Payload $payload)
    {
        return new DeletedResource($payload);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
