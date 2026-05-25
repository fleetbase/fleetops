<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrackingContextBuilder
{
    use ResolvesOrderServiceStops;

    public function build(Order $order, TrackingOptions $options): TrackingContext
    {
        $order->loadAssignedDriver();
        $order->loadMissing([
            'payload',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'payload.waypoints',
            'payload.waypointMarkers.place',
            'payload.waypointMarkers.trackingNumber.status',
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

        foreach ($this->payloadServiceStops($payload) as $serviceStop) {
            $place    = $serviceStop['place'] ?? null;
            $waypoint = $serviceStop['waypoint'] ?? null;
            $status   = $waypoint instanceof Waypoint
                ? $this->waypointServiceStopStatus($waypoint)
                : $this->endpointServiceStopStatus($payload, $serviceStop);

            if (!$place instanceof Place) {
                continue;
            }

            $stops->push(new TrackingStop(
                uuid: $place->uuid,
                publicId: $place->public_id,
                type: $serviceStop['type'],
                status: $status ?: null,
                place: $place,
                waypoint: $waypoint,
                completed: $this->serviceStopIsComplete($order, $payload, $serviceStop),
                sequence: ++$sequence,
                trackingNumberUuid: $serviceStop['tracking_number_uuid'] ?? null
            ));
        }

        return $stops->values();
    }

    protected function endpointServiceStopStatus($payload, array $serviceStop): ?string
    {
        $trackingNumberUuid = $this->serviceStopTrackingNumberUuid($payload, $serviceStop);
        if (!$trackingNumberUuid) {
            return null;
        }

        return strtolower((string) data_get($this->trackingNumberStatus($trackingNumberUuid), 'code')) ?: null;
    }

    protected function waypointServiceStopStatus(Waypoint $waypoint): ?string
    {
        if (!$waypoint->tracking_number_uuid) {
            return strtolower((string) $waypoint->status_code) ?: null;
        }

        return strtolower((string) data_get($this->trackingNumberStatus($waypoint->tracking_number_uuid), 'code')) ?: null;
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
