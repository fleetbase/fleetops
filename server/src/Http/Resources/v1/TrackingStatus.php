<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Grimzy\LaravelMysqlSpatial\Types\Point;

class TrackingStatus extends FleetbaseResource
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
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'tracking_number_uuid' => $this->when(Http::isInternalRequest(), $this->tracking_number_uuid),
            'proof_uuid'           => $this->when(Http::isInternalRequest(), $this->proof_uuid),
            'status'               => $this->status,
            'details'              => $this->details,
            'code'                 => $this->code,
            'tracking_number'      => $this->trackingNumber(),
            'city'                 => $this->city,
            'province'             => $this->province,
            'postal_code'          => $this->postal_code,
            'country'              => $this->country,
            'location'             => $this->location ?? new Point(0, 0),
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
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
            'id'              => $this->public_id,
            'status'          => $this->status,
            'details'         => $this->details,
            'code'            => $this->code,
            'tracking_number' => $this->trackingNumber(),
            'city'            => $this->city,
            'province'        => $this->province,
            'postal_code'     => $this->postal_code,
            'country'         => $this->country,
            'location'        => $this->location ?? new Point(0, 0),
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }

    /**
     * Returns the correct trackingNumber resource if applicable.
     *
     * @return Illuminate\Http\Resources\Json\JsonResource|null
     */
    public function trackingNumber()
    {
        if (Utils::notEmpty($this->tracking_number_uuid) && Utils::isEmpty($this->trackingNumber)) {
            $this->load(['trackingNumber']);
        }

        if (Utils::isEmpty($this->trackingNumber)) {
            return null;
        }

        return new TrackingNumber($this->trackingNumber);
    }
}
