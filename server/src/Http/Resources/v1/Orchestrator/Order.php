<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Orchestrator;

use Fleetbase\FleetOps\Http\Resources\v1\Index\Driver;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Payload;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle;
use Fleetbase\Http\Resources\FleetbaseResource;

/**
 * Orchestrator Order resource.
 *
 * A workbench-specific Order representation that extends the lightweight
 * Index/Order shape with custom_field_values. This resource is intentionally
 * separate from Index/Order so that the tabular orders view retains its
 * optimised, minimal payload while the Orchestrator Workbench gets the richer
 * data it needs to render configurable card fields.
 *
 * Used exclusively by OrchestrationController::orders().
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
        return [
            'id'                    => $this->id,
            'uuid'                  => $this->uuid,
            'public_id'             => $this->public_id,
            'internal_id'           => $this->internal_id,
            'company_uuid'          => $this->company_uuid,
            'payload_uuid'          => $this->payload_uuid,
            'order_config_uuid'     => $this->order_config_uuid,
            'driver_assigned_uuid'  => $this->driver_assigned_uuid,
            'vehicle_assigned_uuid' => $this->vehicle_assigned_uuid,
            'tracking'              => $this->trackingNumber?->tracking_number,

            // Minimal order config
            'order_config'          => $this->whenLoaded('orderConfig', function () {
                return [
                    'id'   => $this->orderConfig->public_id,
                    'name' => $this->orderConfig->name,
                    'key'  => $this->orderConfig->key,
                ];
            }),

            // Payload with pickup/dropoff/waypoints
            'payload'               => $this->whenLoaded('payload', function () {
                return new Payload($this->payload);
            }),

            // Lightweight driver
            'driver_assigned'       => $this->whenLoaded('driverAssigned', function () {
                return new Driver($this->driverAssigned);
            }),

            // Lightweight vehicle
            'vehicle_assigned'      => $this->whenLoaded('vehicleAssigned', function () {
                return new Vehicle($this->vehicleAssigned);
            }),

            // Custom field values — the primary addition over Index/Order.
            // Each entry contains the raw value and the parent CustomField
            // definition (name, label, type) so the card renderer can match
            // by field name/label without a separate API call.
            'custom_field_values'   => $this->whenLoaded('customFieldValues', function () {
                return $this->customFieldValues->map(function ($cfv) {
                    return [
                        'id'                => $cfv->uuid,
                        'uuid'              => $cfv->uuid,
                        'custom_field_uuid' => $cfv->custom_field_uuid,
                        'value'             => $cfv->value,
                        'value_type'        => $cfv->value_type,
                        'custom_field'      => $cfv->relationLoaded('customField') && $cfv->customField
                            ? [
                                'id'       => $cfv->customField->uuid,
                                'uuid'     => $cfv->customField->uuid,
                                'name'     => $cfv->customField->name,
                                'label'    => $cfv->customField->label,
                                'type'     => $cfv->customField->type,
                                'required' => (bool) $cfv->customField->required,
                            ]
                            : null,
                    ];
                })->values();
            }),

            // Scalar fields
            'type'                  => $this->type,
            'status'                => $this->status,
            'notes'                 => $this->notes,
            'adhoc'                 => (bool) data_get($this, 'adhoc', false),
            'dispatched'            => (bool) data_get($this, 'dispatched', false),
            'has_driver_assigned'   => $this->has_driver_assigned,
            'is_scheduled'          => $this->is_scheduled,
            'orchestrator_priority' => $this->orchestrator_priority ?? 0,
            'time_window_start'     => $this->time_window_start,
            'time_window_end'       => $this->time_window_end,
            'required_skills'       => $this->required_skills ?? [],

            // Timestamps
            'scheduled_at'          => $this->scheduled_at,
            'dispatched_at'         => $this->dispatched_at,
            'started_at'            => $this->started_at,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,

            // Meta
            'meta'                  => $this->meta ?? [],
        ];
    }
}
