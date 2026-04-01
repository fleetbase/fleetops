<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class MaintenanceSchedule extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request): array
    {
        return $this->withCustomFields([
            'id'                          => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                        => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                   => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'                => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'             => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'             => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            // Polymorphic subject — raw PHP class name preserved so the frontend
            // serializer's normalizePolymorphicType map can convert it correctly.
            'subject_uuid'                => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'                => $this->when(Http::isInternalRequest(), $this->getRawOriginal('subject_type')),
            'subject'                     => $this->whenLoaded('subject', function () {
                return $this->transformMorphResource($this->subject);
            }),
            // Polymorphic default assignee
            'default_assignee_uuid'       => $this->when(Http::isInternalRequest(), $this->default_assignee_uuid),
            'default_assignee_type'       => $this->when(Http::isInternalRequest(), $this->getRawOriginal('default_assignee_type')),
            'default_assignee'            => $this->whenLoaded('defaultAssignee', function () {
                return $this->transformMorphResource($this->defaultAssignee);
            }),
            // Core attributes
            'name'                        => $this->name,
            'type'                        => $this->type,
            'status'                      => $this->status,
            'interval_method'             => $this->interval_method,
            'interval_type'               => $this->interval_type,
            'interval_value'              => $this->interval_value,
            'interval_unit'               => $this->interval_unit,
            'interval_distance'           => $this->interval_distance,
            'interval_engine_hours'       => $this->interval_engine_hours,
            // Baseline readings
            'last_service_odometer'       => $this->last_service_odometer,
            'last_service_engine_hours'   => $this->last_service_engine_hours,
            'last_service_date'           => $this->last_service_date,
            // Next-due thresholds
            'next_due_date'               => $this->next_due_date,
            'next_due_odometer'           => $this->next_due_odometer,
            'next_due_engine_hours'       => $this->next_due_engine_hours,
            // Work-order defaults
            'default_priority'            => $this->default_priority,
            'instructions'                => $this->instructions,
            'meta'                        => data_get($this, 'meta', (object) []),
            'slug'                        => $this->slug,
            // Dates
            'last_triggered_at'           => $this->last_triggered_at,
            'updated_at'                  => $this->updated_at,
            'created_at'                  => $this->created_at,
        ]);
    }

    /**
     * Resolve the appropriate resource transformer for a polymorphic morph target
     * and return its array representation.
     */
    protected function transformMorphResource($model): ?array
    {
        if (!$model) {
            return null;
        }

        $resourceClass = \Fleetbase\Support\Find::httpResourceForModel($model);
        if ($resourceClass) {
            return (new $resourceClass($model))->resolve();
        }

        return (new \Illuminate\Http\Resources\Json\JsonResource($model))->resolve();
    }
}
