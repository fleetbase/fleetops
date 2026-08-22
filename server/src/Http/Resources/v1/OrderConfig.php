<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

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
            'id'           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'    => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid' => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'key'          => $this->key,
            'name'         => $this->name,
            'namespace'    => $this->namespace,
            'description'  => $this->description,
            'tags'         => $this->tags,
            'status'       => $this->status,
            'version'      => $this->version,
            'flow'         => $this->projectFlow(),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }

    /**
     * Project the flow JSON into a list of activities, keeping the public-safe
     * per-activity fields.
     *
     * `code` and `status` (the label) are the contract; `complete`, `color`,
     * `details`, `pod_method` and `require_pod` are useful UI hints that ride
     * along.
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

        $activities = [];
        foreach ($flow as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $activities[] = [
                'code'        => $activity['code'] ?? ($activity['key'] ?? null),
                'status'      => $activity['status'] ?? null,
                'details'     => $activity['details'] ?? null,
                'color'       => $activity['color'] ?? null,
                'complete'    => (bool) ($activity['complete'] ?? false),
                'pod_method'  => $activity['pod_method'] ?? null,
                'require_pod' => (bool) ($activity['require_pod'] ?? false),
                'sequence'    => isset($activity['sequence']) ? (int) $activity['sequence'] : null,
                'activities'  => static::projectTransitions($activity),
                'logic'       => $activity['logic'] ?? null,
            ];
        }

        return $activities;
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
