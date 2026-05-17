<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Illuminate\Support\Str;

class TrackingProviderRegistry
{
    protected array $providers = [];

    public function register(TrackingProviderInterface|string $provider, ?string $key = null): self
    {
        $instance                      = is_string($provider) ? app($provider) : $provider;
        $providerKey                   = Str::snake($key ?? $instance->key());
        $this->providers[$providerKey] = $instance;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[Str::snake($key)]);
    }

    public function get(string $key): ?TrackingProviderInterface
    {
        return $this->providers[Str::snake($key)] ?? null;
    }

    public function all(): array
    {
        return $this->providers;
    }
}
