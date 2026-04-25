<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class WorkOrder extends FleetbaseResource
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
            'schedule_uuid'           => $this->when(Http::isInternalRequest(), $this->schedule_uuid),
            'created_by_uuid'         => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'         => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            // Polymorphic target
            'target_uuid'             => $this->when(Http::isInternalRequest(), $this->target_uuid),
            'target_type'             => $this->when(Http::isInternalRequest(), $this->target_type ? Utils::toEmberResourceType($this->target_type) : null),
            'target'                  => $this->whenLoaded('target', function () {
                return $this->setTargetType($this->transformMorphResource($this->target));
            }),
            // Polymorphic assignee
            'assignee_uuid'           => $this->when(Http::isInternalRequest(), $this->assignee_uuid),
            'assignee_type'           => $this->when(Http::isInternalRequest(), $this->assignee_type ? Utils::toEmberResourceType($this->assignee_type) : null),
            'assignee'                => $this->whenLoaded('assignee', function () {
                return $this->setAssigneeType($this->transformMorphResource($this->assignee));
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
     * Inject the abstract 'maintenance-subject' type into the embedded target object
     * so Ember Data can resolve the correct polymorphic model.
     *
     * The subject_type must be the Ember Data model name for the concrete subtype,
     * e.g. 'maintenance-subject-vehicle' or 'maintenance-subject-equipment'.
     *
     * We derive it from the bare PHP class basename (e.g. 'Vehicle' -> 'vehicle')
     * rather than using toEmberResourceType() which produces 'fleet-ops:vehicle' —
     * a string that would make the injected type 'maintenance-subject-fleet-ops:vehicle'.
     */
    protected function setTargetType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        // class_basename('Fleetbase\FleetOps\Models\Vehicle') -> 'Vehicle'
        // Str::kebab('Vehicle') -> 'vehicle'
        $bareSlug = Str::kebab(class_basename($this->target_type ?? ''));

        data_set($resolved, 'type', 'maintenance-subject-' . $bareSlug);
        data_set($resolved, 'subject_type', 'maintenance-subject-' . $bareSlug);

        return $resolved;
    }

    /**
     * Inject the abstract 'facilitator' type into the embedded assignee object
     * so Ember Data can resolve the correct polymorphic model.
     *
     * The facilitator_type must be the Ember Data model name for the concrete subtype,
     * e.g. 'facilitator-vendor' or 'facilitator-contact'.
     *
     * We derive it from the bare PHP class basename (e.g. 'Vendor' -> 'vendor')
     * rather than using toEmberResourceType() which produces 'fleet-ops:vendor' —
     * a string that would make the injected type 'facilitator-fleet-ops:vendor'.
     */
    protected function setAssigneeType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        // class_basename('Fleetbase\FleetOps\Models\Vendor') -> 'Vendor'
        // Str::kebab('Vendor') -> 'vendor'
        $bareSlug = Str::kebab(class_basename($this->assignee_type ?? ''));

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
