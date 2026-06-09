<?php

namespace Fleetbase\FleetOps\Support\FuelProviders;

use Fleetbase\FleetOps\Contracts\FuelProvider;
use Illuminate\Support\Collection;

/**
 * Registry for native and extension-provided fuel provider adapters.
 *
 * Third-party Fleetbase packages can register providers from their service
 * providers with:
 *
 *   $this->callAfterResolving(FuelProviderRegistry::class, function ($registry) {
 *       $registry->register(new FuelProviderDescriptor([...]));
 *   });
 */
class FuelProviderRegistry
{
    protected Collection $providers;

    public function __construct()
    {
        $this->providers = collect();
        $this->loadNativeProviders();
    }

    protected function loadNativeProviders(): void
    {
        foreach (config('fuel-providers.providers', []) as $providerConfig) {
            $this->register(new FuelProviderDescriptor($providerConfig));
        }
    }

    public function register(FuelProviderDescriptor $descriptor): void
    {
        $this->providers->put($descriptor->key, $descriptor);
    }

    public function has(string $key): bool
    {
        return $this->providers->has($key);
    }

    public function all(): Collection
    {
        return $this->providers;
    }

    public function findByKey(string $key): ?FuelProviderDescriptor
    {
        return $this->providers->get($key);
    }

    public function resolve(string $key): FuelProvider
    {
        $descriptor = $this->findByKey($key);

        if (!$descriptor || !$descriptor->driverClass) {
            throw new \InvalidArgumentException("Fuel provider '{$key}' is not registered.");
        }

        if (!class_exists($descriptor->driverClass)) {
            throw new \InvalidArgumentException("Fuel provider driver '{$descriptor->driverClass}' does not exist.");
        }

        $provider = app($descriptor->driverClass);

        if (!$provider instanceof FuelProvider) {
            throw new \InvalidArgumentException('Fuel provider driver must implement FuelProvider.');
        }

        return $provider;
    }
}
