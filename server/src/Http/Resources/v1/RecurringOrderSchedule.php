<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class RecurringOrderSchedule extends FleetbaseResource
{
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        return [
            'id' => $this->when($isInternal, $this->id, $this->public_id),
            'uuid' => $this->when($isInternal, $this->uuid),
            'public_id' => $this->when($isInternal, $this->public_id),
            'company_uuid' => $this->when($isInternal, $this->company_uuid),
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'rrule' => $this->rrule,
            'last_materialized_at' => $this->last_materialized_at,
            'materialization_horizon' => $this->materialization_horizon,
            'customer_uuid' => $this->when($isInternal, $this->customer_uuid),
            'customer_type' => $this->when($isInternal, $this->customer_type),
            'facilitator_uuid' => $this->when($isInternal, $this->facilitator_uuid),
            'facilitator_type' => $this->when($isInternal, $this->facilitator_type),
            'order_config_uuid' => $this->when($isInternal, $this->order_config_uuid),
            'driver_assigned_uuid' => $this->when($isInternal, $this->driver_assigned_uuid),
            'vehicle_assigned_uuid' => $this->when($isInternal, $this->vehicle_assigned_uuid),
            'service_rate_uuid' => $this->when($isInternal, $this->service_rate_uuid),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer),
            'facilitator' => $this->whenLoaded('facilitator', fn () => $this->facilitator),
            'order_config' => $this->whenLoaded('orderConfig', fn () => $this->orderConfig),
            'driver_assigned' => $this->whenLoaded('driverAssigned', fn () => $this->driverAssigned),
            'vehicle_assigned' => $this->whenLoaded('vehicleAssigned', fn () => $this->vehicleAssigned),
            'service_rate' => $this->whenLoaded('serviceRate', fn () => $this->serviceRate),
            'template_order_meta' => $this->template_order_meta ?? [],
            'template_payload' => $this->template_payload ?? [],
            'template_entities' => $this->template_entities ?? [],
            'upcoming_occurrences' => $this->when($isInternal, $this->getUpcomingOccurrences((int) $request->input('upcoming_limit', 25))),
            'next_occurrence_at' => $this->next_occurrence_at,
            'meta' => $this->meta ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
