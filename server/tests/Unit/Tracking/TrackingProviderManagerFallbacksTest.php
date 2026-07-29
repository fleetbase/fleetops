<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderCapabilities;
use Fleetbase\FleetOps\Tracking\TrackingProviderManager;
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;

/**
 * Covers the TrackingProviderManager fallback chain: unavailable providers
 * emit warnings, throwing providers are logged and skipped, and the first
 * healthy fallback returns its result tagged with fallback_used.
 */
abstract class FleetOpsTrackingFallbackProviderBase implements TrackingProviderInterface
{
    public function capabilities(): TrackingProviderCapabilities
    {
        return new TrackingProviderCapabilities();
    }

    public function canTrack(TrackingContext $context): bool
    {
        return true;
    }
}

function fleetopsTrackingFallbackContext(): TrackingContext
{
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-fallback-1', 'public_id' => 'order_fallback1'], true);

    return new TrackingContext(
        order: $order,
        payload: null,
        driver: null,
        origin: null,
        driverLocation: null,
        stops: collect([]),
        completedStops: collect([]),
        remainingStops: collect([]),
        activeStop: null,
        nextStop: null,
        driverLocationAgeSeconds: null,
    );
}

test('provider fallback chain warns skips failures and tags fallback results', function () {
    app()->instance('cache', new class {
        public function remember($key, $ttl, $callback)
        {
            return $callback();
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');
    $logRecorder = new class {
        public array $warnings = [];

        public function warning($message, array $context = [])
        {
            $this->warnings[] = [$message, $context];
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };
    app()->instance('log', $logRecorder);
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    $unavailable = new class extends FleetOpsTrackingFallbackProviderBase {
        public function key(): string
        {
            return 'primary';
        }

        public function canTrack(TrackingContext $context): bool
        {
            return false;
        }

        public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
        {
            return new TrackingProviderResult(provider: 'primary');
        }
    };
    $flaky = new class extends FleetOpsTrackingFallbackProviderBase {
        public function key(): string
        {
            return 'flaky';
        }

        public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
        {
            throw new RuntimeException('provider backend exploded');
        }
    };
    $backup = new class extends FleetOpsTrackingFallbackProviderBase {
        public function key(): string
        {
            return 'backup';
        }

        public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
        {
            return new TrackingProviderResult(provider: 'backup', distanceMeters: 1234.0);
        }
    };

    $registry = new TrackingProviderRegistry();
    $registry->register($unavailable)->register($flaky)->register($backup);

    $manager = new TrackingProviderManager($registry);
    $result  = $manager->track(fleetopsTrackingFallbackContext(), new TrackingOptions(provider: 'primary', fallbacks: ['flaky', 'backup']));

    expect($result->provider)->toBe('backup')
        ->and($result->distanceMeters)->toBe(1234.0)
        ->and($result->warnings)->toContain('provider_unavailable:primary')
        ->and($result->warnings)->toContain('provider_failed:flaky')
        ->and($result->warnings)->toContain('fallback_used')
        ->and($logRecorder->warnings)->toHaveCount(1)
        ->and($logRecorder->warnings[0][0])->toBe('Tracking provider failed.');
});
