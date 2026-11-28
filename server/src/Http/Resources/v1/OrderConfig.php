<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Public, read-only projection of an OrderConfig.
 *
 * Exposes the activity `flow` so consumers can render status filter chips,
 * activity labels, and progress UI without learning the internal config
 * schema. Internal-only fields (entities JSON, version, namespace columns
 * that aren't part of the public contract) are filtered out.
 */
class OrderConfig extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'                    => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                  => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'             => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'          => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'key'                   => $this->key,
            'name'                  => $this->name,
            'namespace'             => $this->namespace,
            'description'           => $this->description,
            'tags'                  => $this->tags,
            'status'                => $this->status,
            'version'               => $this->version,
            'flow'                  => $this->projectFlow(),
            // Every policy setting, defaults filled in: what a customer app needs to know about
            // ordering, cancelling, payment methods and the like for this order type.
            'policies'              => $this->resource->resolvedPolicies(),
            'triggers'              => $this->when(Http::isInternalRequest(), $this->triggers ?? []),
            'custom_fields'         => $this->when($this->resource->relationLoaded('customFields'), fn () => $this->projectCustomFields()),
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
        ];
    }

    /**
     * The custom fields orders of the config carry, with what a form needs to ask for them.
     */
    protected function projectCustomFields(): array
    {
        return $this->resource->customFields->map(fn ($field) => [
            'name'          => $field->name,
            'label'         => $field->label,
            'type'          => $field->type,
            'component'     => $field->component,
            'options'       => $field->options,
            'required'      => (bool) $field->required,
            'default_value' => $field->default_value,
            'help_text'     => $field->help_text,
        ])->values()->all();
    }

    /**
     * Project the flow JSON into a list of activities, keeping the public-safe
     * per-activity fields.
     *
     * `code` and `status` (the label) are the contract; `complete`, `color`,
     * `details`, `pod_method`, `require_pod`, `require_inspection` and the
     * inspection form it names (`inspection_form`, a public id, with
     * `inspection_form_name`) are useful UI hints that ride along.
     *
     * `activities`, `sequence` and `logic` describe the flow's *shape*, and are
     * published because without them the list cannot be sequenced. The stored
     * flow is a directed graph — `activities` names the codes an activity can
     * transition to, `sequence` orders activities reachable from the same
     * parent, and `logic` gates availability — which is precisely what
     * `OrderConfig::nextActivity()` walks server-side. Projecting them away
     * left consumers with an unordered set: a client rendering progress from
     * array position marks a dispatched order complete whenever `completed`
     * happens to be declared earlier, and can offer no next step at all.
     *
     * These are descriptions of the configured workflow, not internal state, so
     * there is nothing here a consumer of the config should not see.
     */
    protected function projectFlow(): array
    {
        $flow = $this->flow;
        if (!is_array($flow) || empty($flow)) {
            return [];
        }

        $formNames  = $this->inspectionFormNames($flow);
        $activities = [];
        foreach ($flow as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $requiresInspection = Utils::isTrue($activity['require_inspection'] ?? false);
            $storedForm         = is_string($activity['inspection_form'] ?? null) ? $activity['inspection_form'] : null;
            $form               = $storedForm ? ($formNames[$storedForm] ?? null) : null;
            $activities[]       = [
                'code'                 => $activity['code'] ?? ($activity['key'] ?? null),
                'status'               => $activity['status'] ?? null,
                'details'              => $activity['details'] ?? null,
                'color'                => $activity['color'] ?? null,
                'complete'             => (bool) ($activity['complete'] ?? false),
                'pod_method'           => $activity['pod_method'] ?? null,
                'require_pod'          => (bool) ($activity['require_pod'] ?? false),
                'require_inspection'   => $requiresInspection,
                'inspection_form'      => $requiresInspection ? ($form['public_id'] ?? $storedForm) : null,
                'inspection_form_name' => $requiresInspection ? ($form['name'] ?? ($activity['inspection_form_name'] ?? null)) : null,
                'sequence'             => isset($activity['sequence']) ? (int) $activity['sequence'] : null,
                'activities'           => static::projectTransitions($activity),
                'logic'                => $activity['logic'] ?? null,
            ];
        }

        return $activities;
    }

    /**
     * The inspection forms the flow's activities name, by the id each stored (public id or uuid),
     * read in one query. The name is the form's own, so a renamed form reads as it is now.
     *
     * @return array<string, array{public_id: string, name: ?string}>
     */
    protected function inspectionFormNames(array $flow): array
    {
        $ids = collect($flow)
            ->filter(fn ($activity) => is_array($activity) && Utils::isTrue($activity['require_inspection'] ?? false) && is_string($activity['inspection_form'] ?? null))
            ->pluck('inspection_form')
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $forms = InspectionForm::query()
            ->where('company_uuid', $this->company_uuid)
            ->where(fn ($query) => $query->whereIn('public_id', $ids)->orWhereIn('uuid', $ids))
            ->get(['uuid', 'public_id', 'name']);

        $byId = [];
        foreach ($forms as $form) {
            $entry                   = ['public_id' => $form->public_id, 'name' => $form->name];
            $byId[$form->public_id]  = $entry;
            $byId[$form->uuid]       = $entry;
        }

        return $byId;
    }

    /**
     * The codes an activity can transition to, as a list of strings.
     *
     * Stored flows have been written both ways over time: a bare list of codes,
     * and a list of objects carrying a `code`. Normalising here means consumers
     * do not each have to guess which one they were handed.
     */
    protected static function projectTransitions(array $activity): array
    {
        $transitions = $activity['activities'] ?? [];
        if (!is_array($transitions)) {
            return [];
        }

        $codes = [];
        foreach ($transitions as $transition) {
            if (is_string($transition)) {
                $codes[] = $transition;
                continue;
            }

            if (is_array($transition) && isset($transition['code']) && is_string($transition['code'])) {
                $codes[] = $transition['code'];
            }
        }

        return $codes;
    }
}
