<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;

/**
 * Lightweight Driver resource for index views.
 * Only includes essential identification and display information.
 */
class Driver extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        return [
            'id'              => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'            => $this->when($isInternal, $this->uuid),
            'public_id'       => $this->when($isInternal, $this->public_id),
            'company_uuid'    => $this->when($isInternal, $this->company_uuid),
            'user_uuid'       => $this->when($isInternal, $this->user_uuid),
            'vehicle_uuid'    => $this->when($isInternal, $this->vehicle_uuid),
            'vendor_uuid'     => $this->when($isInternal, $this->vendor_uuid),
            'current_job_uuid'=> $this->when($isInternal, $this->current_job_uuid),
            'name'            => $this->name,
            'vehicle_name'    => $this->when($isInternal, $this->vehicle_name),
            'phone'           => $this->phone,
            'photo_url'       => $this->photo_url,
            'status'          => $this->status,
            'location'        => $this->wasRecentlyCreated ? new Point(0, 0) : data_get($this, 'location', new Point(0, 0)),
            'heading'         => (int) data_get($this, 'heading', 0),
            'altitude'        => (int) data_get($this, 'altitude', 0),
            'speed'           => (int) data_get($this, 'speed', 0),
            'online'          => data_get($this, 'online', false),
        ];
    }
}
