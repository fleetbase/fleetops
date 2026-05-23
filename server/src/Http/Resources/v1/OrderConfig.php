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
     *
     * @return array
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
     * Project the flow JSON into an ordered list of activities, keeping only
     * the public-safe per-activity fields. `code` and `status` (the label)
     * are the contract; `complete`, `color`, `details`, `pod_method`, and
     * `require_pod` are useful UI hints that can ride along.
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
                'code'        => $activity['code']        ?? ($activity['key'] ?? null),
                'status'      => $activity['status']      ?? null,
                'details'     => $activity['details']     ?? null,
                'color'       => $activity['color']       ?? null,
                'complete'    => (bool) ($activity['complete'] ?? false),
                'pod_method'  => $activity['pod_method']  ?? null,
                'require_pod' => (bool) ($activity['require_pod'] ?? false),
            ];
        }

        return $activities;
    }
}
