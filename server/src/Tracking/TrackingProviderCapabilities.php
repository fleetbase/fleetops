<?php

namespace Fleetbase\FleetOps\Tracking;

class TrackingProviderCapabilities implements \JsonSerializable
{
    public function __construct(
        public bool $traffic = false,
        public bool $perLegEta = false,
        public bool $mapMatching = false,
        public bool $routeGeometry = false,
        public array $extras = [],
    ) {
    }

    public function toArray(): array
    {
        return array_merge([
            'traffic'        => $this->traffic,
            'per_leg_eta'    => $this->perLegEta,
            'map_matching'   => $this->mapMatching,
            'route_geometry' => $this->routeGeometry,
        ], $this->extras);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
