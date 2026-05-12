<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TrackingIntelligenceService
{
    public function __construct(
        protected TrackingContextBuilder $contextBuilder,
        protected TrackingProviderManager $providerManager,
    ) {
    }

    public function track(Order $order, array|TrackingOptions $options = []): array
    {
        $options  = $options instanceof TrackingOptions ? $options : TrackingOptions::fromArray($options);
        $context  = $this->contextBuilder->build($order, $options);
        $cacheKey = $this->cacheKey($context, $options);

        return Cache::remember($cacheKey, $options->cacheTtlSeconds, function () use ($context, $options) {
            return $this->buildResult($context, $this->providerManager->track($context, $options), $options);
        });
    }

    public function eta(Order $order, array|TrackingOptions $options = []): array
    {
        $tracker = $this->track($order, $options);

        return [
            'active_stop_seconds' => data_get($tracker, 'eta.active_stop_seconds'),
            'completion_seconds'  => data_get($tracker, 'eta.completion_seconds'),
            'active_stop_at'      => data_get($tracker, 'eta.active_stop_at'),
            'completion_at'       => data_get($tracker, 'eta.completion_at'),
            'provider'            => data_get($tracker, 'provider'),
            'confidence'          => data_get($tracker, 'confidence'),
            'warnings'            => data_get($tracker, 'warnings', []),
        ];
    }

    protected function buildResult(TrackingContext $context, TrackingProviderResult $providerResult, TrackingOptions $options): array
    {
        $now               = now();
        $completionSeconds = $providerResult->durationInTrafficSeconds ?? $providerResult->durationSeconds;
        $activeStopSeconds = data_get($providerResult->legs, '0.duration_in_traffic_s', data_get($providerResult->legs, '0.duration_s', $completionSeconds));
        $progress          = $this->progress($context, $providerResult);
        $warnings          = array_values(array_unique([...$context->warnings, ...$providerResult->warnings]));

        if ($providerResult->confidence === 'low') {
            $warnings[] = 'low_confidence_eta';
        }

        return [
            'provider'          => $providerResult->provider,
            'fallback_provider' => in_array('fallback_used', $warnings) ? $providerResult->provider : null,
            'generated_at'      => $now->toISOString(),
            'confidence'        => $providerResult->confidence,
            'warnings'          => array_values(array_unique($warnings)),
            'driver'            => [
                'location'             => $this->pointToGeoJson($context->driverLocation),
                'location_age_seconds' => $context->driverLocationAgeSeconds,
                'online'               => (bool) data_get($context->driver, 'online', false),
            ],
            'progress'          => $progress,
            'stops'             => $context->stops->map(fn (TrackingStop $stop) => $stop->toArray())->values()->all(),
            'active_stop'       => $context->activeStop?->toArray(),
            'next_stop'         => $context->nextStop?->toArray(),
            'route'             => [
                'distance_m'            => $providerResult->distanceMeters,
                'duration_s'            => $providerResult->durationSeconds,
                'duration_in_traffic_s' => $providerResult->durationInTrafficSeconds,
                'polyline'              => $providerResult->polyline,
                'coordinates'           => $providerResult->coordinates,
                'legs'                  => $this->legs($context, $providerResult, $now),
            ],
            'eta'               => [
                'active_stop_seconds' => $activeStopSeconds,
                'completion_seconds'  => $completionSeconds,
                'active_stop_at'      => $this->addSeconds($now, $activeStopSeconds),
                'completion_at'       => $this->addSeconds($now, $completionSeconds),
            ],
            'insights'          => [
                'is_delayed'          => false,
                'delay_seconds'       => 0,
                'is_location_stale'   => in_array('stale_driver_location', $warnings),
                'is_off_route'        => in_array('off_route', $warnings),
            ],
            'capabilities'      => $this->capabilitiesFor($providerResult->provider),
        ];
    }

    protected function progress(TrackingContext $context, TrackingProviderResult $providerResult): array
    {
        $totalStops     = max($context->stops->count(), 1);
        $completedStops = $context->completedStops->count();
        $percentage     = $context->order->status === 'completed' ? 100 : round(($completedStops / $totalStops) * 100, 2);

        return [
            'percentage'           => $percentage,
            'completed_stops'      => $completedStops,
            'remaining_stops'      => $context->remainingStops->count(),
            'total_stops'          => $context->stops->count(),
            'completed_distance_m' => null,
            'remaining_distance_m' => $providerResult->distanceMeters,
        ];
    }

    protected function legs(TrackingContext $context, TrackingProviderResult $providerResult, Carbon $now): array
    {
        $elapsedSeconds = 0;

        return collect($providerResult->legs)->map(function ($leg, $index) use ($context, $now, &$elapsedSeconds) {
            $stop        = $context->remainingStops->values()->get($index);
            $legSeconds  = data_get($leg, 'duration_in_traffic_s', data_get($leg, 'duration_s'));
            $etaSeconds  = null;
            $etaAt       = null;

            if ($legSeconds !== null) {
                $elapsedSeconds += (float) $legSeconds;
                $etaSeconds = $elapsedSeconds;
                $etaAt      = $this->addSeconds($now, $etaSeconds);
            }

            return array_merge($leg, [
                'stop'        => $stop instanceof TrackingStop ? $stop->toArray() : null,
                'eta_seconds' => $etaSeconds,
                'eta_at'      => $etaAt,
            ]);
        })->all();
    }

    protected function capabilitiesFor(string $provider): array
    {
        $registry   = app(TrackingProviderRegistry::class);
        $registered = $registry->get($provider);

        return $registered ? $registered->capabilities()->toArray() : (new TrackingProviderCapabilities())->toArray();
    }

    protected function cacheKey(TrackingContext $context, TrackingOptions $options): string
    {
        return 'order_tracking_intelligence:' . md5(json_encode([
            'order'      => $context->order->uuid,
            'order_ts'   => optional($context->order->updated_at)->timestamp,
            'payload'    => optional($context->payload)->uuid,
            'payload_ts' => optional($context->payload?->updated_at)->timestamp,
            'driver'     => optional($context->driver)->uuid,
            'driver_ts'  => optional($context->driver?->updated_at)->timestamp,
            'stops'      => $context->stateSignature(),
            'provider'   => $options->provider,
            'fallbacks'  => $options->fallbacks,
            'traffic'    => $options->trafficEnabled,
            'settings'   => [
                'summary_ttl'     => $options->cacheTtlSeconds,
                'route_ttl'       => $options->routeCacheTtlSeconds,
                'stale_threshold' => $options->staleLocationThresholdSeconds,
                'fallback_speed'  => $options->defaultVehicleSpeedKph,
            ],
        ]));
    }

    protected function pointToGeoJson($point): ?array
    {
        if (!$point) {
            return null;
        }

        return [
            'type'        => 'Point',
            'coordinates' => [$point->getLng(), $point->getLat()],
        ];
    }

    protected function addSeconds(Carbon $now, ?float $seconds): ?string
    {
        return $seconds === null ? null : $now->copy()->addSeconds((int) round($seconds))->toISOString();
    }
}
