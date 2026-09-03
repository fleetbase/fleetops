<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class InspectionForm extends FleetbaseResource
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
            'id'             => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'           => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'      => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'   => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'=> $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'=> $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            'subject_uuid'   => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'   => $this->when(Http::isInternalRequest(), $this->subject_type ? Utils::toEmberResourceType($this->subject_type) : null),
            'subject'        => $this->whenLoaded('subject', fn () => $this->setSubjectType($this->transformMorphResource($this->subject))),
            'name'           => $this->name,
            'description'    => $this->description,
            'type'           => $this->type,
            'status'         => $this->status,
            'frequency'      => $this->frequency,
            'items'          => data_get($this, 'items', []),
            'settings'       => data_get($this, 'settings', (object) []),
            'meta'           => data_get($this, 'meta', (object) []),
            'subject_name'   => $this->subject_name,
            'item_count'     => $this->item_count,
            'is_published'   => $this->is_published,
            'published_at'   => $this->published_at,
            'updated_at'     => $this->updated_at,
            'created_at'     => $this->created_at,
        ]);
    }

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
