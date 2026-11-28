<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Proof extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'         => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'       => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'  => $this->when(Http::isInternalRequest(), $this->public_id),
            'subject_id' => $this->subject ? $this->subject->public_id : null,
            'order_id'   => $this->order ? $this->order->public_id : null,
            // What kind of proof this is, so a client can draw it without guessing from the remarks.
            'type'         => $this->kind(),
            // What it was captured against: the order itself, or one of its items.
            'subject_type' => $this->subject_type ? strtolower(class_basename($this->subject_type)) : null,
            'url'        => $this->file_url,
            'remarks'    => $this->remarks,
            'raw'        => $this->raw_data,
            'data'       => $this->data,
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * signature, photo or scan: a signature is stored under the signatures folder, a photo
     * is any other stored file, and a scan stores no file at all.
     */
    protected function kind(): string
    {
        $path = (string) data_get($this->resource, 'file.path', '');
        if (str_contains($path, '/signatures/')) {
            return 'signature';
        }

        return $this->resource->file_uuid ? 'photo' : 'scan';
    }
}
