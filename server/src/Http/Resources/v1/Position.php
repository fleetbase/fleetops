<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;

class Position extends FleetbaseResource
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
            'id'                              => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                            => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                       => $this->when(Http::isInternalRequest(), $this->public_id),
            'order_uuid'                      => $this->when(Http::isInternalRequest(), $this->order_uuid),
            'company_uuid'                    => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'destination_uuid'                => $this->when(Http::isInternalRequest(), $this->destination_uuid),
            'subject_uuid'                    => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'                    => $this->subject_type,
            'subject'                         => $this->whenLoaded('subject', fn () => Resolve::httpResourceForModel($this->subject)),
            'order'                           => $this->whenLoaded('order', fn () => new Order($this->order)),
            'destination'                     => $this->whenLoaded('destination', fn () => new Place($this->destination)),
            'heading'                         => $this->heading ?? 0,
            'bearing'                         => $this->bearing ?? 0,
            'speed'                           => $this->speed ?? 0,
            'altitude'                        => $this->altitude ?? 0,
            'latitude'                        => $this->latitude ?? 0,
            'longitude'                       => $this->longitude ?? 0,
            'coordinates'                     => $this->coordinates ?? new Point(0, 0),
            'updated_at'                      => $this->updated_at,
            'created_at'                      => $this->created_at,
        ];
    }
}
