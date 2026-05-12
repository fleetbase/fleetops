<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TrackingProviderManager
{
    public function __construct(protected TrackingProviderRegistry $registry)
    {
    }

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        $warnings = [];
        foreach ($this->providerOrder($options) as $providerKey) {
            $provider = $this->registry->get($providerKey);
            if (!$provider instanceof TrackingProviderInterface) {
                $warnings[] = 'provider_not_registered:' . $providerKey;
                continue;
            }

            if (!$provider->canTrack($context)) {
                $warnings[] = 'provider_unavailable:' . $providerKey;
                continue;
            }

            try {
                $result           = $provider->track($context, $options);
                $result->warnings = array_values(array_unique([...$warnings, ...$result->warnings]));

                if (Str::snake($result->provider) !== Str::snake((string) $options->provider)) {
                    $result->warnings[] = 'fallback_used';
                }

                return $result;
            } catch (\Throwable $e) {
                $warnings[] = 'provider_failed:' . $providerKey;
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
        $provider  = $options->provider ?: Arr::get(config('fleetops.tracking', []), 'provider', 'google_routes');
        $fallbacks = $options->fallbacks ?: Arr::get(config('fleetops.tracking', []), 'fallbacks', ['osrm', 'calculated']);

        return collect([$provider, ...$fallbacks])
            ->filter()
            ->map(fn ($key) => Str::snake($key))
            ->unique()
            ->values()
            ->all();
    }
}
