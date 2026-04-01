<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class WorkOrder extends FleetbaseResource
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
            'schedule_uuid'           => $this->when(Http::isInternalRequest(), $this->schedule_uuid),
            'created_by_uuid'         => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'         => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            // Polymorphic target — raw PHP class name preserved so the frontend
            // serializer's normalizePolymorphicType map can convert it correctly.
            'target_uuid'             => $this->when(Http::isInternalRequest(), $this->target_uuid),
            'target_type'             => $this->when(Http::isInternalRequest(), $this->getRawOriginal('target_type')),
            'target'                  => $this->whenLoaded('target', function () {
                return $this->transformMorphResource($this->target);
            }),
            // Polymorphic assignee
            'assignee_uuid'           => $this->when(Http::isInternalRequest(), $this->assignee_uuid),
            'assignee_type'           => $this->when(Http::isInternalRequest(), $this->getRawOriginal('assignee_type')),
            'assignee'                => $this->whenLoaded('assignee', function () {
                return $this->transformMorphResource($this->assignee);
            }),
            // Core attributes
            'code'                    => $this->code,
            'subject'                 => $this->subject,
            'status'                  => $this->status,
            'priority'                => $this->priority,
            'instructions'            => $this->instructions,
            'checklist'               => data_get($this, 'checklist', []),
            'meta'                    => data_get($this, 'meta', (object) []),
            'slug'                    => $this->slug,
            // Computed
            'target_name'             => $this->target_name,
            'assignee_name'           => $this->assignee_name,
            'is_overdue'              => $this->is_overdue,
            'days_until_due'          => $this->days_until_due,
            'completion_percentage'   => $this->completion_percentage,
            // Dates
            'opened_at'               => $this->opened_at,
            'due_at'                  => $this->due_at,
            'closed_at'               => $this->closed_at,
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
