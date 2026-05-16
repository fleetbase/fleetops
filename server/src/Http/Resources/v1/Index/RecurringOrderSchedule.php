<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

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
            'name'                    => $this->name,
            'description'             => $this->description,
            'status'                  => $this->status,
            'timezone'                => $this->timezone,
            'starts_at'               => $this->starts_at,
            'ends_at'                 => $this->ends_at,
            'rrule'                   => $this->rrule,
            'customer_uuid'           => $this->when($isInternal, $this->customer_uuid),
            'order_config_uuid'       => $this->when($isInternal, $this->order_config_uuid),
            'service_rate_uuid'       => $this->when($isInternal, $this->service_rate_uuid),
            'customer_name'           => $this->whenLoaded('customer', fn () => data_get($this->customer, 'name')),
            'order_config_name'       => $this->whenLoaded('orderConfig', fn () => data_get($this->orderConfig, 'name')),
            'service_rate_name'       => $this->whenLoaded('serviceRate', fn () => data_get($this->serviceRate, 'service_name')),
            'next_occurrence_at'      => $this->next_occurrence_at,
            'generated_orders_count'  => $this->when($isInternal, fn () => $this->generated_orders_count ?? $this->generatedOrders()->count()),
            'materialization_horizon' => $this->materialization_horizon,
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
            'meta'                    => ['_index_resource' => true],
        ];
    }
}
