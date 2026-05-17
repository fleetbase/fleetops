<?php

namespace Fleetbase\FleetOps\Tracking\Providers;

use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderCapabilities;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleRoutesTrackingProvider implements TrackingProviderInterface
{
    public function key(): string
    {
        return 'google_routes';
    }

    public function capabilities(): TrackingProviderCapabilities
    {
        return new TrackingProviderCapabilities(traffic: true, perLegEta: true, routeGeometry: true);
    }

    public function canTrack(TrackingContext $context): bool
    {
        return $context->canRoute() && filled($this->apiKey());
    }

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        $points      = $context->routePoints();
        $origin      = array_shift($points);
        $destination = array_pop($points);
        $body        = [
            'origin'                   => ['location' => ['latLng' => $this->latLng($origin)]],
            'destination'              => ['location' => ['latLng' => $this->latLng($destination)]],
            'travelMode'               => 'DRIVE',
            'routingPreference'        => $options->trafficEnabled ? 'TRAFFIC_AWARE_OPTIMAL' : 'TRAFFIC_UNAWARE',
            'computeAlternativeRoutes' => false,
            'languageCode'             => 'en-US',
            'units'                    => 'METRIC',
        ];

        if (!empty($points)) {
            $body['intermediates'] = array_map(fn ($point) => ['location' => ['latLng' => $this->latLng($point)]], $points);
        }

        $response = Http::timeout(5)
            ->withHeaders([
                'X-Goog-Api-Key'   => $this->apiKey(),
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.staticDuration,routes.polyline.encodedPolyline,routes.legs.distanceMeters,routes.legs.duration,routes.legs.staticDuration',
            ])
            ->post('https://routes.googleapis.com/directions/v2:computeRoutes', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('Google Routes request failed with status ' . $response->status());
        }

        $route = data_get($response->json(), 'routes.0');
        if (!$route) {
            throw new \RuntimeException('Google Routes returned no route.');
        }

        $duration        = $this->durationToSeconds(data_get($route, 'staticDuration')) ?? $this->durationToSeconds(data_get($route, 'duration'));
        $trafficDuration = $this->durationToSeconds(data_get($route, 'duration'));

        return new TrackingProviderResult(
            provider: $this->key(),
            distanceMeters: data_get($route, 'distanceMeters'),
            durationSeconds: $duration,
            durationInTrafficSeconds: $options->trafficEnabled ? $trafficDuration : null,
            polyline: data_get($route, 'polyline.encodedPolyline'),
            legs: $this->legs(data_get($route, 'legs', []), $options),
            warnings: [],
            confidence: $options->trafficEnabled ? 'high' : 'medium',
            raw: $route
        );
    }

    protected function legs(array $legs, TrackingOptions $options): array
    {
        return collect($legs)->map(function ($leg, $index) use ($options) {
            $duration        = $this->durationToSeconds(data_get($leg, 'staticDuration')) ?? $this->durationToSeconds(data_get($leg, 'duration'));
            $trafficDuration = $this->durationToSeconds(data_get($leg, 'duration'));

            return [
                'index'                 => $index,
                'distance_m'            => data_get($leg, 'distanceMeters'),
                'duration_s'            => $duration,
                'duration_in_traffic_s' => $options->trafficEnabled ? $trafficDuration : null,
                'provider'              => $this->key(),
            ];
        })->all();
    }

    protected function latLng($point): array
    {
        return [
            'latitude'  => $point->getLat(),
            'longitude' => $point->getLng(),
        ];
    }

    protected function durationToSeconds(?string $duration): ?float
    {
        if (!$duration) {
            return null;
        }

        return (float) Str::replaceLast('s', '', $duration);
    }

    protected function apiKey(): ?string
    {
        return config('services.google_maps.api_key') ?: env('GOOGLE_MAPS_API_KEY');
    }
}
