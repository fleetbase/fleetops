<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\Comment;
use Fleetbase\Http\Resources\File;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;

class Order extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        // Precompute expensive bits safely
        $orderConfigPublicId = data_get($this->orderConfig, 'public_id');

        // Tracker helpers (avoid calling ->tracker() twice)
        $withTrackerData = $request->has('with_tracker_data') || !empty($this->resource->tracker_data);
        $withEta         = $request->has('with_eta') || !empty($this->resource->eta);
        $tracker         = ($withTrackerData || $withEta) ? $this->resource->tracker() : null;

        return $this->withCustomFields([
            'id'                   => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'                 => $this->when($isInternal, $this->uuid),
            'public_id'            => $this->when($isInternal, $this->public_id),
            'internal_id'          => $this->internal_id,
            'company_uuid'         => $this->when($isInternal, $this->company_uuid),
            'transaction_uuid'     => $this->when($isInternal, $this->transaction_uuid),
            'customer_uuid'        => $this->when($isInternal, $this->customer_uuid),
            'customer_type'        => $this->when($isInternal, $this->customer_type ? Utils::toEmberResourceType($this->customer_type) : null),
            'facilitator_uuid'     => $this->when($isInternal, $this->facilitator_uuid),
            'facilitator_type'     => $this->when($isInternal, $this->facilitator_type ? Utils::toEmberResourceType($this->facilitator_type) : null),
            'payload_uuid'         => $this->when($isInternal, $this->payload_uuid),
            'route_uuid'           => $this->when($isInternal, $this->route_uuid),
            'purchase_rate_uuid'   => $this->when($isInternal, $this->purchase_rate_uuid),
            'tracking_number_uuid' => $this->when($isInternal, $this->tracking_number_uuid),
            'driver_assigned_uuid' => $this->when($isInternal, $this->driver_assigned_uuid),
            'vehicle_assigned_uuid'=> $this->when($isInternal, $this->vehicle_assigned_uuid),
            'service_quote_uuid'   => $this->when($isInternal, $this->service_quote_uuid),
            'has_driver_assigned'  => $this->when($isInternal, $this->has_driver_assigned),
            'is_scheduled'         => $this->when($isInternal, $this->is_scheduled),
            'order_config_uuid'    => $this->when($isInternal, $this->order_config_uuid),
            'order_config'         => $this->when(
                $isInternal,
                $this->whenLoaded('orderConfig', function () {
                    return $this->orderConfig;
                }),
                $orderConfigPublicId
            ),
            'customer'             => $this->whenLoaded('customer', function () {
                return $this->setCustomerType($this->transformMorphResource($this->customer));
            }),
            'payload'              => new Payload($this->payload),
            'facilitator'          => $this->whenLoaded('facilitator', function () {
                return $this->setFacilitatorType($this->transformMorphResource($this->facilitator));
            }),
            'driver_assigned'      => $this->whenLoaded('driverAssigned', function () {
                return new Driver($this->driverAssigned);
            }),
            'vehicle_assigned'     => $this->whenLoaded('vehicleAssigned', function () {
                return new Vehicle($this->vehicleAssigned);
            }),
            'tracking_number'      => new TrackingNumber($this->trackingNumber),
            'tracking_statuses'    => $this->whenLoaded('trackingStatuses', function () {
                return TrackingStatus::collection($this->trackingStatuses);
            }),
            'tracking'             => $this->when($isInternal, $this->trackingNumber ? $this->trackingNumber->tracking_number : null),
            'barcode'              => $this->when($isInternal, $this->trackingNumber ? $this->trackingNumber->barcode : null),
            'qr_code'              => $this->when($isInternal, $this->trackingNumber ? $this->trackingNumber->qr_code : null),
            'comments'             => $this->when($isInternal, Comment::collection($this->comments)),
            'files'                => $this->when($isInternal, $this->files, File::collection($this->files)),
            'purchase_rate'        => new PurchaseRate($this->purchaseRate),
            'notes'                => $this->notes,
            'type'                 => $this->type,
            'status'               => $this->status,
            'pod_method'           => $this->pod_method,
            'pod_required'         => (bool) data_get($this, 'pod_required', false),
            'dispatched'           => (bool) data_get($this, 'dispatched', false),
            'adhoc'                => (bool) data_get($this, 'adhoc', false),
            'adhoc_distance'       => (int) $this->getAdhocDistance(),
            'distance'             => (int) $this->distance,
            'time'                 => (int) $this->time,
            'tracker_data'         => $this->when($withTrackerData, function () use ($tracker) {
                return $this->resource->tracker_data ?? ($tracker ? $tracker->toArray() : null);
            }),
            'eta'                  => $this->when($withEta, function () use ($tracker) {
                return $this->resource->eta ?? ($tracker ? $tracker->eta() : null);
            }),
            'meta'                 => data_get($this, 'meta', Utils::createObject()),
            'dispatched_at'        => $this->dispatched_at,
            'started_at'           => $this->started_at,
            'scheduled_at'         => $this->scheduled_at,
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ]);
    }

    /**
     * Set the customer type for the given data array.
     *
     * @param array $resolved the input data array
     *
     * @return array the modified data array with the customer type set
     */
    public function setCustomerType($resolved)
    {
        if (empty($resolved)) {
            return $resolved;
        }

        data_set($resolved, 'type', 'customer');
        data_set($resolved, 'customer_type', 'customer-' . Utils::toEmberResourceType($this->customer_type));

        return $resolved;
    }

    /**
     * Set the facilitator type for the given data array.
     *
     * @param array $resolved the input data array
     *
     * @return array the modified data array with the facilitator type set
     */
    public function setFacilitatorType($resolved)
    {
        if (empty($resolved)) {
            return $resolved;
        }

        data_set($resolved, 'type', 'facilitator');
        data_set($resolved, 'facilitator_type', 'facilitator-' . Utils::toEmberResourceType($this->facilitator_type));

        return $resolved;
    }

    /**
     * Transform a polymorphic relationship into its appropriate resource.
     * This method dynamically resolves the resource class based on the model type.
     *
     * @param \Illuminate\Database\Eloquent\Model|null $model
     *
     * @return array|null
     */
    protected function transformMorphResource($model)
    {
        if (!$model) {
            return null;
        }

        // Use Find to get the appropriate resource class for this model
        $resourceClass = \Fleetbase\Support\Find::httpResourceForModel($model);

        if ($resourceClass) {
            return (new $resourceClass($model))->resolve();
        }

        // Fallback to generic resource
        return (new \Illuminate\Http\Resources\Json\JsonResource($model))->resolve();
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'              => $this->public_id,
            'internal_id'     => $this->internal_id,
            'customer'        => $this->transformMorphResource($this->customer),
            'payload'         => new Payload($this->payload),
            'facilitator'     => $this->transformMorphResource($this->facilitator),
            'driver_assigned' => new Driver($this->driverAssigned),
            'tracking_number' => new TrackingNumber($this->trackingNumber),
            'purchase_rate'   => new PurchaseRate($this->purchaseRate),
            'notes'           => $this->notes ?? '',
            'type'            => $this->type ?? null,
            'status'          => $this->status,
            'adhoc'           => $this->adhoc,
            'meta'            => data_get($this, 'meta', Utils::createObject()),
            'dispatched_at'   => $this->dispatched_at,
            'started_at'      => $this->started_at,
            'scheduled_at'    => $this->scheduled_at,
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }
}
