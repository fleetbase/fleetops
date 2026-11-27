<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Support\Routing\RoutingProviderPolicy;
use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TrackingProviderManager
{
    /** Engines that answer with a line along roads, in the order they are tried after the organization's own. */
    public const ROAD_ENGINES = ['valhalla', 'osrm', 'google_routes'];

    /** How long an engine that just failed is left alone, so every request does not wait out its timeout. */
    public const FAILURE_REST_SECONDS = 120;

    public function __construct(protected TrackingProviderRegistry $registry)
    {
    }

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        $warnings = [];
        foreach ($this->providerOrder($options) as $providerKey) {
            // Providers switched off in Admin → Fleet-Ops → Routing are never called, even when requested
            if (!RoutingProviderPolicy::allows($providerKey, $context->order->company_uuid)) {
                $warnings[] = 'provider_disabled:' . $providerKey;
                continue;
            }

            $provider = $this->registry->get($providerKey);
            if (!$provider instanceof TrackingProviderInterface) {
                $warnings[] = 'provider_not_registered:' . $providerKey;
                continue;
            }

            if (!$provider->canTrack($context)) {
                $warnings[] = 'provider_unavailable:' . $providerKey;
                continue;
            }

            // An engine that failed a moment ago is not asked again yet
            $restKey = 'order_tracking_provider_resting:' . $providerKey . ':' . $context->order->company_uuid;
            if ($providerKey !== 'calculated' && Cache::has($restKey)) {
                $warnings[] = 'provider_failed:' . $providerKey;
                continue;
            }

            try {
                $result           = $this->trackWithProviderCache($provider, $context, $options);
                $result->warnings = array_values(array_unique([...$warnings, ...$result->warnings]));

                if (TrackingProviderRegistry::normalizeKey($result->provider) !== TrackingProviderRegistry::normalizeKey($options->provider)) {
                    $result->warnings[] = 'fallback_used';
                }

                return $result;
            } catch (\Throwable $e) {
                $warnings[] = 'provider_failed:' . $providerKey;
                Cache::put($restKey, true, static::FAILURE_REST_SECONDS);
                Log::warning('Tracking provider failed.', [
                    'provider' => $providerKey,
                    'order'    => $context->order->public_id ?? $context->order->uuid,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return new TrackingProviderResult(
            provider: 'none',
            warnings: array_values(array_unique([...$warnings, 'no_tracking_provider_available'])),
            confidence: 'none'
        );
    }

    protected function providerOrder(TrackingOptions $options): array
    {
        // The organization's engine first, then what the caller asked for, then every other road
        // engine the install has: a route along roads from a second engine is worth more to a
        // driver than a straight line from the first. Each is still subject to the routing policy
        // and to having a host or key, so an engine nobody set up is skipped at no cost. The
        // straight-line estimate always closes the chain so every order gets an answer.
        return collect([$options->provider, ...$options->fallbacks, ...static::ROAD_ENGINES, 'calculated'])
            ->filter()
            ->map(fn ($key) => TrackingProviderRegistry::normalizeKey($key))
            ->unique()
            ->values()
            ->all();
    }

    protected function trackWithProviderCache(TrackingProviderInterface $provider, TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        return Cache::remember($this->providerCacheKey($provider, $context, $options), $options->routeCacheTtlSeconds, function () use ($provider, $context, $options) {
            return $provider->track($context, $options);
        });
    }

    protected function providerCacheKey(TrackingProviderInterface $provider, TrackingContext $context, TrackingOptions $options): string
    {
        return 'order_tracking_provider_route:' . md5(json_encode([
            'provider' => $provider->key(),
            'points'   => array_map(function ($point) {
                return [$point->getLat(), $point->getLng()];
            }, $context->routePoints()),
            'traffic'  => $options->trafficEnabled,
            'speed'    => $options->defaultVehicleSpeedKph,
        ]));
    }
}
