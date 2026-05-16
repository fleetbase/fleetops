<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Index\Customer as CustomerIndexResource;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Facilitator as FacilitatorIndexResource;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class RecurringOrderSchedule extends FleetbaseResource
{
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        return [
            'id'                      => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'                    => $this->when($isInternal, $this->uuid),
            'public_id'               => $this->when($isInternal, $this->public_id),
            'company_uuid'            => $this->when($isInternal, $this->company_uuid),
            'name'                    => $this->name,
            'description'             => $this->description,
            'status'                  => $this->status,
            'timezone'                => $this->timezone,
            'starts_at'               => $this->starts_at,
            'ends_at'                 => $this->ends_at,
            'rrule'                   => $this->rrule,
            'last_materialized_at'    => $this->last_materialized_at,
            'materialization_horizon' => $this->materialization_horizon,
            'customer_uuid'           => $this->when($isInternal, $this->customer_uuid),
            'customer_type'           => $this->when($isInternal, $this->customer_type),
            'facilitator_uuid'        => $this->when($isInternal, $this->facilitator_uuid),
            'facilitator_type'        => $this->when($isInternal, $this->facilitator_type),
            'order_config_uuid'       => $this->when($isInternal, $this->order_config_uuid),
            'driver_assigned_uuid'    => $this->when($isInternal, $this->driver_assigned_uuid),
            'vehicle_assigned_uuid'   => $this->when($isInternal, $this->vehicle_assigned_uuid),
            'service_rate_uuid'       => $this->when($isInternal, $this->service_rate_uuid),
            'customer_name'           => $this->whenLoaded('customer', fn () => data_get($this->customer, 'name')),
            'facilitator_name'        => $this->whenLoaded('facilitator', fn () => data_get($this->facilitator, 'name')),
            'order_config_name'       => $this->whenLoaded('orderConfig', fn () => data_get($this->orderConfig, 'name')),
            'driver_assigned_name'    => $this->whenLoaded('driverAssigned', fn () => data_get($this->driverAssigned, 'name')),
            'vehicle_assigned_name'   => $this->whenLoaded('vehicleAssigned', fn () => data_get($this->vehicleAssigned, 'display_name')),
            'service_rate_name'       => $this->whenLoaded('serviceRate', fn () => data_get($this->serviceRate, 'service_name')),
            'customer'                => $this->whenLoaded('customer', fn () => $this->typedRelationship($this->customer, 'customer', CustomerIndexResource::class)),
            'facilitator'             => $this->whenLoaded('facilitator', fn () => $this->typedRelationship($this->facilitator, 'facilitator', FacilitatorIndexResource::class)),
            'order_config'            => $this->whenLoaded('orderConfig', fn () => $this->typedRelationship($this->orderConfig, 'order-config')),
            'driver_assigned'         => $this->whenLoaded('driverAssigned', fn () => $this->typedRelationship($this->driverAssigned, 'driver', Driver::class)),
            'vehicle_assigned'        => $this->whenLoaded('vehicleAssigned', fn () => $this->typedRelationship($this->vehicleAssigned, 'vehicle', Vehicle::class)),
            'service_rate'            => $this->whenLoaded('serviceRate', fn () => $this->typedRelationship($this->serviceRate, 'service-rate', ServiceRate::class)),
            'template_order_meta'     => $this->template_order_meta ?? [],
            'template_payload'        => $this->template_payload ?? [],
            'template_entities'       => $this->template_entities ?? [],
            'upcoming_occurrences'    => $this->when($isInternal, fn () => $this->resource->getUpcomingOccurrences((int) $request->input('upcoming_limit', 25))),
            'history_occurrences'     => $this->when($isInternal, fn () => $this->resource->getOccurrenceHistory((int) $request->input('history_limit', 25))),
            'next_occurrence_at'      => $this->next_occurrence_at,
            'generated_orders_count'  => $this->when($isInternal, fn () => $this->generated_orders_count ?? $this->generatedOrders()->count()),
            'meta'                    => $this->meta ?? [],
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
        ];
    }

    protected function typedRelationship($model, string $type, ?string $resourceClass = null): ?array
    {
        if (!$model) {
            return null;
        }

        $data = $resourceClass ? (new $resourceClass($model))->resolve() : $model->toArray();

        data_set($data, 'type', $type);

        return $data;
    }
}
