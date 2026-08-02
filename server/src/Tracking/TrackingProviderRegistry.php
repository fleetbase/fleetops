<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;

class TrackingProviderRegistry
{
    protected array $providers = [];

    public static function normalizeKey(?string $key): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $key)), '_');
    }

    public function register(TrackingProviderInterface|string $provider, ?string $key = null): self
    {
        $instance                      = is_string($provider) ? app($provider) : $provider;
        $providerKey                   = static::normalizeKey($key ?? $instance->key());
        $this->providers[$providerKey] = $instance;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[static::normalizeKey($key)]);
    }

    public function get(string $key): ?TrackingProviderInterface
    {
        return $this->providers[static::normalizeKey($key)] ?? null;
    }

    public function all(): array
    {
        return $this->providers;
    }
}
