<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class TrackingStop implements \JsonSerializable
{
    public function __construct(
        public ?string $uuid,
        public ?string $publicId,
        public string $type,
        public ?string $status,
        public ?Place $place,
        public ?Waypoint $waypoint = null,
        public bool $completed = false,
        public ?int $sequence = null,
        public ?string $trackingNumberUuid = null,
    ) {
    }

    public function point(): ?Point
    {
        try {
            return Utils::getPointFromMixed($this->place);
        } catch (\Throwable) {
            return null;
        }
    }

    public function toArray(): array
    {
        $point = $this->point();

        return [
            'uuid'                 => $this->uuid,
            'public_id'            => $this->publicId,
            'type'                 => $this->type,
            'status'               => $this->status,
            'completed'            => $this->completed,
            'sequence'             => $this->sequence,
            'tracking_number_uuid' => $this->trackingNumberUuid,
            'address'              => data_get($this->place, 'address'),
            'name'                 => data_get($this->place, 'name'),
            'location'             => $point ? [
                'type'        => 'Point',
                'coordinates' => [$point->getLng(), $point->getLat()],
            ] : null,
            'latitude'             => $point ? $point->getLat() : null,
            'longitude'            => $point ? $point->getLng() : null,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
