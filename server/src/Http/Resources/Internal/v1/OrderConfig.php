<?php

namespace Fleetbase\FleetOps\Http\Resources\Internal\v1;

use Fleetbase\Http\Resources\FleetbaseResource;

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
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'public_id'     => $this->public_id,
            'company_uuid'  => $this->company_uuid,
            'author_uuid'   => $this->author_uuid,
            'category_uuid' => $this->category_uuid,
            'icon_uuid'     => $this->icon_uuid,
            'name'          => $this->name,
            'namespace'     => $this->namespace,
            'description'   => $this->description,
            'key'           => $this->key,
            'status'        => $this->status,
            'version'       => $this->version,
            'core_service'  => (bool) $this->core_service,
            'flow'          => $this->flow ?? [],
            'entities'      => $this->entities ?? [],
            'tags'          => $this->tags ?? [],
            'meta'          => $this->meta ?? [],
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            'deleted_at'    => $this->deleted_at,
            'type'          => 'order-config',
        ];
    }
}
