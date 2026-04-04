<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class MaintenanceSchedule extends FleetbaseResource
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
            'id'                          => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                        => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                   => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'                => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'             => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'             => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            // Polymorphic subject
            'subject_uuid'                => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'                => $this->when(Http::isInternalRequest(), $this->subject_type ? Utils::toEmberResourceType($this->subject_type) : null),
            'subject'                     => $this->whenLoaded('subject', function () {
                return $this->setSubjectType($this->transformMorphResource($this->subject));
            }),
            // Polymorphic default assignee
            'default_assignee_uuid'       => $this->when(Http::isInternalRequest(), $this->default_assignee_uuid),
            'default_assignee_type'       => $this->when(Http::isInternalRequest(), $this->default_assignee_type ? Utils::toEmberResourceType($this->default_assignee_type) : null),
            'default_assignee'            => $this->whenLoaded('defaultAssignee', function () {
                return $this->setAssigneeType($this->transformMorphResource($this->defaultAssignee));
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
            'reminder_offsets'            => $this->reminder_offsets ?? [],
            'meta'                        => data_get($this, 'meta', (object) []),
            'slug'                        => $this->slug,
            // Convenience name attrs
            'subject_name'                => $this->subject_name,
            'default_assignee_name'       => $this->default_assignee_name,
            // Dates
            'last_triggered_at'           => $this->last_triggered_at,
            'updated_at'                  => $this->updated_at,
            'created_at'                  => $this->created_at,
        ]);
    }

    /**
     * Inject the abstract 'maintenance-subject' type into the embedded subject object
     * so Ember Data can resolve the correct polymorphic model.
     *
     * subject_type must be the Ember Data model name for the concrete subtype,
     * e.g. 'maintenance-subject-vehicle' or 'maintenance-subject-equipment'.
     * We use the bare PHP class basename so we get 'vehicle' / 'equipment' rather
     * than the full 'fleet-ops:vehicle' string that toEmberResourceType() would return.
     */
    protected function setSubjectType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        $bareSlug = Str::kebab(class_basename($this->subject_type ?? ''));

        data_set($resolved, 'type', 'maintenance-subject-' . $bareSlug);
        data_set($resolved, 'subject_type', 'maintenance-subject-' . $bareSlug);

        return $resolved;
    }

    /**
     * Inject the abstract 'facilitator' type into the embedded default_assignee object
     * so Ember Data can resolve the correct polymorphic model.
     *
     * facilitator_type must be the Ember Data model name for the concrete subtype,
     * e.g. 'facilitator-vendor' or 'facilitator-contact'.
     * We use the bare PHP class basename so we get 'vendor' / 'contact' rather
     * than the full 'fleet-ops:vendor' string that toEmberResourceType() would return.
     */
    protected function setAssigneeType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        $bareSlug = Str::kebab(class_basename($this->default_assignee_type ?? ''));

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
