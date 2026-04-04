<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class Maintenance extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return $this->withCustomFields([
            'id'                      => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                    => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'               => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'            => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'work_order_uuid'         => $this->when(Http::isInternalRequest(), $this->work_order_uuid),
            'created_by_uuid'         => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'         => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            // Polymorphic maintainable
            'maintainable_uuid'       => $this->when(Http::isInternalRequest(), $this->maintainable_uuid),
            'maintainable_type'       => $this->when(Http::isInternalRequest(), $this->maintainable_type ? Utils::toEmberResourceType($this->maintainable_type) : null),
            'maintainable'            => $this->whenLoaded('maintainable', function () {
                return $this->setMaintainableType($this->transformMorphResource($this->maintainable));
            }),
            // Polymorphic performed_by
            'performed_by_uuid'       => $this->when(Http::isInternalRequest(), $this->performed_by_uuid),
            'performed_by_type'       => $this->when(Http::isInternalRequest(), $this->performed_by_type ? Utils::toEmberResourceType($this->performed_by_type) : null),
            'performed_by'            => $this->whenLoaded('performedBy', function () {
                return $this->setPerformedByType($this->transformMorphResource($this->performedBy));
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
            'currency'                => $this->currency,
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
     * Inject the abstract 'maintenance-subject' type into the embedded maintainable object
     * so Ember Data can resolve the correct polymorphic model.
     *
     * subject_type must be the Ember Data model name for the concrete subtype,
     * e.g. 'maintenance-subject-vehicle' or 'maintenance-subject-equipment'.
     * We use the bare PHP class basename so we get 'vehicle' / 'equipment' rather
     * than the full 'fleet-ops:vehicle' string that toEmberResourceType() would return.
     */
    protected function setMaintainableType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        $bareSlug = Str::kebab(class_basename($this->maintainable_type ?? ''));

        data_set($resolved, 'type', 'maintenance-subject-' . $bareSlug);
        data_set($resolved, 'subject_type', 'maintenance-subject-' . $bareSlug);

        return $resolved;
    }

    /**
     * Inject the abstract 'facilitator' type into the embedded performed_by object
     * so Ember Data can resolve the correct polymorphic model.
     *
     * facilitator_type must be the Ember Data model name for the concrete subtype,
     * e.g. 'facilitator-vendor' or 'facilitator-contact'.
     * We use the bare PHP class basename so we get 'vendor' / 'contact' rather
     * than the full 'fleet-ops:vendor' string that toEmberResourceType() would return.
     */
    protected function setPerformedByType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        $bareSlug = Str::kebab(class_basename($this->performed_by_type ?? ''));

        data_set($resolved, 'type', 'facilitator-' . $bareSlug);
        data_set($resolved, 'facilitator_type', 'facilitator-' . $bareSlug);

        return $resolved;
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
