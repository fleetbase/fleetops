<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Comment extends FleetbaseResource
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
        return [
            'id'                => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'              => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'         => $this->when(Http::isInternalRequest(), $this->public_id),
            'commenter_uuid'    => $this->when(Http::isInternalRequest(), $this->commenter_uuid),
            'comment'           => $this->comment,
            'order_uuid'        => $this->when(Http::isInternalRequest(), $this->order_uuid),
            'parent_comment_id' => $this->parent_comment_id,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
            'soft_deleted'      => $this->trashed(),
        ];
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id' => $this->public_id,
            'commenter_uuid' => $this->commenter_uuid,
            'comment' => $this->comment,
            'order_uuid' => $this->order_uuid,
            'parent_comment_id' => $this->parent_comment_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'soft_deleted' => $this->trashed(),
        ];
    }
}
