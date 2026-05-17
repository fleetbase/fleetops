<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrackingContextBuilder
{
    public function build(Order $order, TrackingOptions $options): TrackingContext
    {
        $order->loadAssignedDriver();
        $order->loadMissing([
            'payload',
            'payload.pickup',
            'payload.dropoff',
            'payload.waypoints',
            'payload.waypointMarkers.place',
        ]);

        $payload           = $order->payload;
        $driver            = $order->driverAssigned;
        $warnings          = [];
        $driverLocationAge = $driver?->updated_at ? now()->diffInSeconds($driver->updated_at) : null;

        $origin         = null;
        $driverLocation = null;
        if ($driver && $driver->location) {
            try {
                $driverLocation = Utils::getPointFromMixed($driver);
                if (!$this->isValidPoint($driverLocation)) {
                    $driverLocation = null;
                }
            } catch (\Throwable) {
                $driverLocation = null;
            }
        }

        if ($driverLocation) {
            $origin = $driverLocation;
        } else {
            $warnings[] = 'missing_driver_location';
            $origin     = $this->fallbackOrigin($payload);
        }

        if ($driverLocationAge !== null && $driverLocationAge > $options->staleLocationThresholdSeconds) {
            $warnings[] = 'stale_driver_location';
        }

        $stops          = $this->stops($payload, $order);
        $completedStops = $stops->filter(fn (TrackingStop $stop) => $stop->completed)->values();
        $remainingStops = $stops->reject(fn (TrackingStop $stop) => $stop->completed)->values();
        $activeStop     = $this->activeStop($payload, $remainingStops);
        $nextStop       = $remainingStops->first(fn (TrackingStop $stop) => !$activeStop || $stop->uuid !== $activeStop->uuid);

        if (!$activeStop && $remainingStops->isNotEmpty()) {
            $activeStop = $remainingStops->first();
        }

        $missingStopLocations = $remainingStops->filter(fn (TrackingStop $stop) => !$stop->point())->count();
        if ($missingStopLocations > 0) {
            $warnings[] = 'missing_stop_location';
        }

        return new TrackingContext(
            order: $order,
            payload: $payload,
            driver: $driver,
            origin: $origin,
            driverLocation: $driverLocation,
            stops: $stops,
            completedStops: $completedStops,
            remainingStops: $remainingStops,
            activeStop: $activeStop,
            nextStop: $nextStop,
            driverLocationAgeSeconds: $driverLocationAge,
            warnings: array_values(array_unique($warnings))
        );
    }

    protected function stops($payload, Order $order): Collection
    {
        if (!$payload) {
            return collect();
        }

        $stops    = collect();
        $sequence = 0;

        if ($payload->pickup instanceof Place) {
            $stops->push(new TrackingStop(
                uuid: $payload->pickup->uuid,
                publicId: $payload->pickup->public_id,
                type: 'pickup',
                status: null,
                place: $payload->pickup,
                completed: !in_array($order->status, ['created', 'dispatched', 'pending']),
                sequence: ++$sequence
            ));
        }

        $markers = $payload->waypointMarkers instanceof Collection ? $payload->waypointMarkers : collect();
        if ($markers->isNotEmpty()) {
            foreach ($markers->sortBy('order')->values() as $waypoint) {
                $stops->push($this->stopFromWaypoint($waypoint, ++$sequence));
            }
        } elseif ($payload->waypoints instanceof Collection) {
            foreach ($payload->waypoints as $place) {
                if ($place instanceof Place) {
                    $stops->push(new TrackingStop(
                        uuid: $place->uuid,
                        publicId: $place->public_id,
                        type: 'waypoint',
                        status: null,
                        place: $place,
                        completed: false,
                        sequence: ++$sequence
                    ));
                }
            }
        }

        if ($payload->dropoff instanceof Place) {
            $stops->push(new TrackingStop(
                uuid: $payload->dropoff->uuid,
                publicId: $payload->dropoff->public_id,
                type: 'dropoff',
                status: null,
                place: $payload->dropoff,
                completed: in_array($order->status, ['completed', 'canceled']),
                sequence: ++$sequence
            ));
        }

        return $stops->values();
    }

    protected function stopFromWaypoint(Waypoint $waypoint, int $sequence): TrackingStop
    {
        $waypoint->loadMissing('place');
        $status = strtolower((string) $waypoint->status_code);

        return new TrackingStop(
            uuid: $waypoint->place_uuid,
            publicId: data_get($waypoint, 'place.public_id'),
            type: 'waypoint',
            status: $status ?: null,
            place: $waypoint->place,
            waypoint: $waypoint,
            completed: in_array($status, ['completed', 'canceled']),
            sequence: $sequence
        );
    }

    protected function activeStop($payload, Collection $remainingStops): ?TrackingStop
    {
        if (!$payload || !$payload->current_waypoint_uuid || !Str::isUuid($payload->current_waypoint_uuid)) {
            return $remainingStops->first();
        }

        return $remainingStops->first(function (TrackingStop $stop) use ($payload) {
            return $stop->uuid === $payload->current_waypoint_uuid
                || data_get($stop->waypoint, 'uuid') === $payload->current_waypoint_uuid
                || data_get($stop->waypoint, 'place_uuid') === $payload->current_waypoint_uuid;
        });
    }

    protected function fallbackOrigin($payload)
    {
        if (!$payload) {
            return null;
        }

        try {
            $point = Utils::getPointFromMixed($payload->getPickupOrCurrentWaypoint());

            return $this->isValidPoint($point) ? $point : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isValidPoint($point): bool
    {
        if (!$point || !method_exists($point, 'getLat') || !method_exists($point, 'getLng')) {
            return false;
        }

        $lat = (float) $point->getLat();
        $lng = (float) $point->getLng();

        return is_finite($lat)
            && is_finite($lng)
            && $lat >= -90
            && $lat <= 90
            && $lng >= -180
            && $lng <= 180
            && !(abs($lat) < 0.000001 && abs($lng) < 0.000001);
    }
}
