<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Maintenance extends FleetbaseResource
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
            'id'                      => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                    => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'               => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'            => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'work_order_uuid'         => $this->when(Http::isInternalRequest(), $this->work_order_uuid),
            'created_by_uuid'         => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'         => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            // Polymorphic maintainable — raw PHP class name preserved so the frontend
            // serializer's normalizePolymorphicType map can convert it correctly.
            'maintainable_uuid'       => $this->when(Http::isInternalRequest(), $this->maintainable_uuid),
            'maintainable_type'       => $this->when(Http::isInternalRequest(), $this->getRawOriginal('maintainable_type')),
            'maintainable'            => $this->whenLoaded('maintainable', function () {
                return $this->transformMorphResource($this->maintainable);
            }),
            // Polymorphic performed_by
            'performed_by_uuid'       => $this->when(Http::isInternalRequest(), $this->performed_by_uuid),
            'performed_by_type'       => $this->when(Http::isInternalRequest(), $this->getRawOriginal('performed_by_type')),
            'performed_by'            => $this->whenLoaded('performedBy', function () {
                return $this->transformMorphResource($this->performedBy);
            }),
            // Core attributes
            'type'                    => $this->type,
            'status'                  => $this->status,
            'priority'                => $this->priority,
            'odometer'                => $this->odometer,
            'engine_hours'            => $this->engine_hours,
            'summary'                 => $this->summary,
            'notes'                   => $this->notes,
            'line_items'              => data_get($this, 'line_items', []),
            'labor_cost'              => $this->labor_cost,
            'parts_cost'              => $this->parts_cost,
            'tax'                     => $this->tax,
            'total_cost'              => $this->total_cost,
            'attachments'             => data_get($this, 'attachments', []),
            'meta'                    => data_get($this, 'meta', (object) []),
            'slug'                    => $this->slug,
            // Computed
            'maintainable_name'       => $this->maintainable_name,
            'work_order_subject'      => $this->work_order_subject,
            'performed_by_name'       => $this->performed_by_name,
            'duration_hours'          => $this->duration_hours,
            'is_overdue'              => $this->is_overdue,
            'days_until_due'          => $this->days_until_due,
            'cost_breakdown'          => $this->cost_breakdown,
            // Dates
            'scheduled_at'            => $this->scheduled_at,
            'started_at'              => $this->started_at,
            'completed_at'            => $this->completed_at,
            'updated_at'              => $this->updated_at,
            'created_at'              => $this->created_at,
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
