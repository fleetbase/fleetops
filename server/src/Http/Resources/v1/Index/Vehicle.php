<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;

/**
 * Lightweight Vehicle resource for index views.
 * Only includes essential identification and display information.
 */
class Vehicle extends FleetbaseResource
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
            'display_name'    => $this->display_name,
            'plate_number'    => $this->plate_number,
            'make'            => $this->make,
            'model'           => $this->model,
            'year'            => $this->year,
            'photo_url'       => $this->photo_url,
            'status'          => $this->status,
            'location'        => data_get($this, 'location', new Point(0, 0)),
            'heading'         => (int) data_get($this, 'heading', 0),
            'altitude'        => (int) data_get($this, 'altitude', 0),
            'speed'           => (int) data_get($this, 'speed', 0),
            'online'          => (bool) data_get($this, 'online', false),
        ];
    }
}
