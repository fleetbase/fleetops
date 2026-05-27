<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TrackingIntelligenceService
{
    protected const TERMINAL_STATUSES = ['completed', 'canceled'];

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
            'start_seconds'       => data_get($tracker, 'eta.start_seconds'),
            'start_at'            => data_get($tracker, 'eta.start_at'),
            'provider'            => data_get($tracker, 'provider'),
            'confidence'          => data_get($tracker, 'confidence'),
            'lifecycle'           => data_get($tracker, 'lifecycle'),
            'warnings'            => data_get($tracker, 'warnings', []),
        ];
    }

    protected function buildResult(TrackingContext $context, TrackingProviderResult $providerResult, TrackingOptions $options): array
    {
        $now                  = now();
        $rawCompletionSeconds = $providerResult->durationInTrafficSeconds ?? $providerResult->durationSeconds;
        $rawActiveStopSeconds = data_get($providerResult->legs, '0.duration_in_traffic_s', data_get($providerResult->legs, '0.duration_s', $rawCompletionSeconds));
        $lifecycle            = $this->lifecycle($context, $rawActiveStopSeconds, $now);
        $showsLiveEta         = (bool) data_get($lifecycle, 'show_live_eta');
        $showsStartEta        = (bool) data_get($lifecycle, 'show_start_eta');
        $completionSeconds    = $showsLiveEta ? $rawCompletionSeconds : null;
        $activeStopSeconds    = $showsLiveEta ? $rawActiveStopSeconds : null;
        $startSeconds         = $showsStartEta ? $rawActiveStopSeconds : null;
        $progress             = $this->progress($context, $providerResult);
        $warnings             = array_values(array_unique([...$context->warnings, ...$providerResult->warnings]));

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
            'lifecycle'         => $lifecycle,
            'stops'             => $context->stops->map(fn (TrackingStop $stop) => $stop->toArray())->values()->all(),
            'active_stop'       => $context->activeStop?->toArray(),
            'next_stop'         => $context->nextStop?->toArray(),
            'route'             => [
                'distance_m'            => $providerResult->distanceMeters,
                'duration_s'            => $providerResult->durationSeconds,
                'duration_in_traffic_s' => $providerResult->durationInTrafficSeconds,
                'polyline'              => $providerResult->polyline,
                'coordinates'           => $providerResult->coordinates,
                'legs'                  => $this->legs($context, $providerResult, $now, $showsLiveEta),
            ],
            'eta'               => [
                'active_stop_seconds' => $activeStopSeconds,
                'completion_seconds'  => $completionSeconds,
                'active_stop_at'      => $this->addSeconds($now, $activeStopSeconds),
                'completion_at'       => $this->addSeconds($now, $completionSeconds),
                'start_seconds'       => $startSeconds,
                'start_at'            => $this->addSeconds($now, $startSeconds),
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

    protected function legs(TrackingContext $context, TrackingProviderResult $providerResult, Carbon $now, bool $showsLiveEta = true): array
    {
        $elapsedSeconds = 0;

        return collect($providerResult->legs)->map(function ($leg, $index) use ($context, $now, $showsLiveEta, &$elapsedSeconds) {
            $stop        = $context->remainingStops->values()->get($index);
            $legSeconds  = data_get($leg, 'duration_in_traffic_s', data_get($leg, 'duration_s'));
            $etaSeconds  = null;
            $etaAt       = null;

            if ($showsLiveEta && $stop instanceof TrackingStop && $legSeconds !== null) {
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

    protected function lifecycle(TrackingContext $context, ?float $startSeconds, Carbon $now): array
    {
        $order             = $context->order;
        $status            = strtolower((string) ($order->status ?? 'created'));
        $hasAssignedDriver = !empty($order->driver_assigned_uuid);
        $hasStarted        = $this->hasOrderStarted($order);
        $isTerminal        = in_array($status, self::TERMINAL_STATUSES, true);
        $isDispatched      = $status === 'dispatched' || (bool) $order->dispatched;

        if ($isTerminal) {
            return [
                'status'         => $status,
                'mode'           => $status,
                'message'        => $status === 'completed' ? 'Order has been completed.' : 'Order has been canceled.',
                'is_terminal'    => true,
                'has_started'    => $hasStarted,
                'show_live_eta'  => false,
                'show_start_eta' => false,
            ];
        }

        if (!$hasAssignedDriver) {
            return [
                'status'         => $status,
                'mode'           => 'unassigned',
                'message'        => 'Assign a driver to start live tracking and improve ETA accuracy.',
                'is_terminal'    => false,
                'has_started'    => $hasStarted,
                'show_live_eta'  => false,
                'show_start_eta' => false,
            ];
        }

        if (!$hasStarted && $isDispatched) {
            return [
                'status'         => $status,
                'mode'           => 'dispatched',
                'message'        => 'Order has been dispatched. Estimated start is based on the assigned driver route to the first stop.',
                'is_terminal'    => false,
                'has_started'    => false,
                'show_live_eta'  => false,
                'show_start_eta' => $startSeconds !== null,
                'start_at'       => $this->addSeconds($now, $startSeconds),
            ];
        }

        if (!$hasStarted) {
            return [
                'status'         => $status,
                'mode'           => 'pre_start',
                'message'        => 'Live ETA will begin once the order is started.',
                'is_terminal'    => false,
                'has_started'    => false,
                'show_live_eta'  => false,
                'show_start_eta' => false,
            ];
        }

        return [
            'status'         => $status,
            'mode'           => 'active',
            'message'        => null,
            'is_terminal'    => false,
            'has_started'    => true,
            'show_live_eta'  => true,
            'show_start_eta' => false,
        ];
    }

    protected function hasOrderStarted(Order $order): bool
    {
        if ((bool) $order->started || $order->started_at || strtolower((string) $order->status) === 'started') {
            return true;
        }

        try {
            if ($order->relationLoaded('trackingStatuses')) {
                return $order->trackingStatuses->contains(fn ($status) => strtolower((string) data_get($status, 'code')) === 'started');
            }

            return $order->trackingStatuses()->whereRaw('LOWER(code) = ?', ['started'])->exists();
        } catch (\Throwable) {
            return false;
        }
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
