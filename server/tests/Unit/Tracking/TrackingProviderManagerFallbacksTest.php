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

test('osrm tracking provider requests real routes through the http client', function () {
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
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response([
        'code'   => 'Ok',
        'routes' => [[
            'distance' => 5200,
            'duration' => 780,
            'geometry' => '_p~iF~ps|U_ulLnnqC',
            'legs'     => [['distance' => 5200, 'duration' => 780]],
        ]],
    ]));

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-osrm-1', 'public_id' => 'order_osrm1'], true);
    $point   = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.30, 103.80);
    $stop    = null;
    $context = new TrackingContext(
        order: $order,
        payload: null,
        driver: null,
        origin: $point,
        driverLocation: $point,
        stops: collect([]),
        completedStops: collect([]),
        remainingStops: collect([]),
        activeStop: $stop,
        nextStop: null,
        driverLocationAgeSeconds: null,
    );

    // routePoints needs at least two points; add a destination stop whose
    // place surfaces a concrete location
    $destinationPlace = new class extends Fleetbase\FleetOps\Models\Place {
        public ?object $locationFake = null;

        public function getAttribute($key)
        {
            if ($key === 'location') {
                return $this->locationFake;
            }

            return parent::getAttribute($key);
        }
    };
    $destinationPlace->setRawAttributes(['uuid' => 'place-osrm-1'], true);
    $destinationPlace->locationFake = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.31, 103.81);

    $destination = new Fleetbase\FleetOps\Tracking\TrackingStop(
        uuid: 'stop-osrm-1',
        publicId: 'stop_osrmone1',
        type: 'dropoff',
        status: null,
        place: $destinationPlace,
    );
    $context->remainingStops->push($destination);

    $provider = new Fleetbase\FleetOps\Tracking\Providers\OsrmTrackingProvider();
    $result   = $provider->track($context, new TrackingOptions());

    expect($result->provider)->toBe($provider->key())
        ->and($result->distanceMeters)->toBe(5200.0)
        ->and($result->legs)->toHaveCount(1)
        ->and($result->warnings)->toContain('no_live_traffic');
});
