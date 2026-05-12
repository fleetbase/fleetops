<?php

namespace Fleetbase\FleetOps\Tracking;

class TrackingProviderResult
{
    public function __construct(
        public string $provider,
        public ?float $distanceMeters = null,
        public ?float $durationSeconds = null,
        public ?float $durationInTrafficSeconds = null,
        public ?string $polyline = null,
        public array $coordinates = [],
        public array $legs = [],
        public array $warnings = [],
        public string $confidence = 'medium',
        public ?array $raw = null,
    ) {
    }
}
