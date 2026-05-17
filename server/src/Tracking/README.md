# Order Tracking Providers

FleetOps tracking providers adapt third-party routing or tracking systems into the canonical tracking intelligence response.

Providers must implement `Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface`:

```php
public function key(): string;
public function capabilities(): TrackingProviderCapabilities;
public function canTrack(TrackingContext $context): bool;
public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult;
```

Register providers from a service provider:

```php
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;

public function boot(): void
{
    app(TrackingProviderRegistry::class)->register(new TomTomTrackingProvider());
}
```

Provider adapters should never expose vendor response shapes directly. Map provider responses into `TrackingProviderResult` and declare capabilities such as traffic-aware ETA, per-leg ETA, route geometry, or map matching.
