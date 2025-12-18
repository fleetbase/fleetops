<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Lightweight Order resource optimized for index/list views.
 * This resource includes only the essential data needed to display orders in a table,
 * significantly reducing payload size and improving response times.
 */
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

        return [
            'id'                   => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'                 => $this->when($isInternal, $this->uuid),
            'public_id'            => $this->when($isInternal, $this->public_id),
            'internal_id'          => $this->internal_id,
            'company_uuid'         => $this->when($isInternal, $this->company_uuid),
            'payload_uuid'         => $this->when($isInternal, $this->payload_uuid),
            'driver_assigned_uuid' => $this->when($isInternal, $this->driver_assigned_uuid),
            'vehicle_assigned_uuid'=> $this->when($isInternal, $this->vehicle_assigned_uuid),
            'customer_uuid'        => $this->when($isInternal, $this->customer_uuid),
            'customer_type'        => $this->when($isInternal, $this->customer_type),
            'facilitator_uuid'     => $this->when($isInternal, $this->facilitator_uuid),
            'facilitator_type'     => $this->when($isInternal, $this->facilitator_type),
            'tracking_number_uuid' => $this->when($isInternal, $this->tracking_number_uuid),
            'order_config_uuid'    => $this->when($isInternal, $this->order_config_uuid),

            // Minimal order config - only essential fields
            'order_config'         => $this->when(
                $isInternal,
                $this->whenLoaded('orderConfig', function () {
                    return [
                        'id'   => $this->orderConfig->public_id,
                        'name' => $this->orderConfig->name,
                        'key'  => $this->orderConfig->key,
                    ];
                })
            ),

            // Lightweight customer
            'customer'             => $this->whenLoaded('customer', function () {
                $resource = new Customer($this->customer);
                $data     = $resource->resolve();
                data_set($data, 'type', 'customer');
                data_set($data, 'customer_type', 'customer-' . Utils::toEmberResourceType($this->customer_type));

                return $data;
            }),

            // Lightweight payload
            'payload'              => $this->whenLoaded('payload', function () {
                return new Payload($this->payload);
            }),

            // Lightweight facilitator
            'facilitator'          => $this->whenLoaded('facilitator', function () {
                $resource = new Facilitator($this->facilitator);
                $data     = $resource->resolve();
                data_set($data, 'type', 'facilitator');
                data_set($data, 'facilitator_type', 'facilitator-' . Utils::toEmberResourceType($this->facilitator_type));

                return $data;
            }),

            // Lightweight driver
            'driver_assigned'      => $this->whenLoaded('driverAssigned', function () {
                return new Driver($this->driverAssigned);
            }),

            // Lightweight vehicle
            'vehicle_assigned'     => $this->whenLoaded('vehicleAssigned', function () {
                return new Vehicle($this->vehicleAssigned);
            }),

            // Lightweight tracking number with QR code
            'tracking_number'      => $this->whenLoaded('trackingNumber', function () {
                return new TrackingNumber($this->trackingNumber);
            }),

            // Latest status only, not full array
            'latest_status'        => $this->whenLoaded('trackingStatuses', function () {
                $latest = $this->trackingStatuses->first();

                return $latest ? $latest->status : 'created';
            }),
            'latest_status_code'   => $this->whenLoaded('trackingStatuses', function () {
                $latest = $this->trackingStatuses->first();

                return $latest ? $latest->code : null;
            }),

            // Essential scalar fields
            'type'                 => $this->type,
            'status'               => $this->status,
            'adhoc'                => (bool) data_get($this, 'adhoc', false),
            'dispatched'           => (bool) data_get($this, 'dispatched', false),
            'has_driver_assigned'  => $this->when($isInternal, $this->has_driver_assigned),
            'is_scheduled'         => $this->when($isInternal, $this->is_scheduled),

            // Timestamps
            'scheduled_at'         => $this->scheduled_at,
            'dispatched_at'        => $this->dispatched_at,
            'started_at'           => $this->started_at,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,

            // Meta flag to indicate this is an index resource
            'meta'                 => [
                '_index_resource' => true,
            ],
        ];
    }
}
