<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Collection;

class TrackingContext
{
    public function __construct(
        public Order $order,
        public ?Payload $payload,
        public ?Driver $driver,
        public ?Point $origin,
        public Collection $stops,
        public Collection $completedStops,
        public Collection $remainingStops,
        public ?TrackingStop $activeStop,
        public ?TrackingStop $nextStop,
        public ?int $driverLocationAgeSeconds,
        public array $warnings = [],
    ) {
    }

    public function routePoints(): array
    {
        $points = [];
        if ($this->origin) {
            $points[] = $this->origin;
        }

        foreach ($this->remainingStops as $stop) {
            $point = $stop instanceof TrackingStop ? $stop->point() : null;
            if ($point) {
                $points[] = $point;
            }
        }

        return $points;
    }

    public function canRoute(): bool
    {
        return count($this->routePoints()) >= 2;
    }
}
