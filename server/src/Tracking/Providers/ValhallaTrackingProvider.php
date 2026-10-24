<?php

namespace Fleetbase\FleetOps\Tracking\Providers;

use Fleetbase\FleetOps\Support\Encoding\Polyline;
use Fleetbase\FleetOps\Support\Encoding\Polyline6;
use Fleetbase\FleetOps\Support\Routing\ValhallaClient;
use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderCapabilities;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;

class ValhallaTrackingProvider implements TrackingProviderInterface
{
    public function key(): string
    {
        return 'valhalla';
    }

    public function capabilities(): TrackingProviderCapabilities
    {
        return new TrackingProviderCapabilities(perLegEta: true, routeGeometry: true);
    }

    public function canTrack(TrackingContext $context): bool
    {
        return $context->canRoute() && ValhallaClient::isConfigured();
    }

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        $trip = ValhallaClient::route($context->routePoints());

        $legs   = [];
        $points = [];
        foreach (data_get($trip, 'legs', []) as $index => $leg) {
            $legs[] = [
                'index'                 => $index,
                'distance_m'            => (float) data_get($leg, 'summary.length', 0) * 1000,
                'duration_s'            => (float) data_get($leg, 'summary.time', 0),
                'duration_in_traffic_s' => null,
                'provider'              => $this->key(),
            ];

            // Stitch Valhalla's polyline6 leg shapes into one polyline5, the encoding OSRM and Google return
            foreach (Polyline6::decode((string) data_get($leg, 'shape', '')) as $point) {
                $points[] = [$point->getLng(), $point->getLat()];
            }
        }

        $polyline = $points ? Polyline::encode($points) : null;

        return new TrackingProviderResult(
            provider: $this->key(),
            distanceMeters: (float) data_get($trip, 'summary.length', 0) * 1000,
            durationSeconds: (float) data_get($trip, 'summary.time', 0),
            durationInTrafficSeconds: null,
            polyline: $polyline,
            coordinates: $polyline ? Polyline::decode($polyline) : [],
            legs: $legs,
            warnings: ['no_live_traffic'],
            confidence: 'medium',
            raw: $trip
        );
    }
}
