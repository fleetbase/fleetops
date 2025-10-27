<?php

namespace Fleetbase\FleetOps\Support\Telematics;

use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Illuminate\Support\Collection;

/**
 * Class ProviderRegistry.
 *
 * Central registry for all telematics providers.
 * Manages provider discovery, registration, and instantiation.
 */
class TelematicProviderRegistry
{
    /**
     * @var Collection<TelematicProviderDescriptor>
     */
    protected Collection $providers;

    /**
     * Create a new ProviderRegistry instance.
     */
    public function __construct()
    {
        $this->providers = collect();
        $this->loadNativeProviders();
    }

    /**
     * Load native providers from configuration.
     */
    protected function loadNativeProviders(): void
    {
        $config = config('telematics.providers', []);

        foreach ($config as $providerConfig) {
            $descriptor = new TelematicProviderDescriptor($providerConfig);
            $this->register($descriptor);
        }
    }

    /**
     * Register a provider.
     */
    public function register(TelematicProviderDescriptor $descriptor): void
    {
        $this->providers->put($descriptor->key, $descriptor);
    }

    /**
     * Get all registered providers.
     *
     * @return Collection<TelematicProviderDescriptor>
     */
    public function all(): Collection
    {
        return $this->providers;
    }

    /**
     * Find a provider by key.
     */
    public function findByKey(string $key): ?TelematicProviderDescriptor
    {
        return $this->providers->get($key);
    }

    /**
     * Check if a provider exists.
     */
    public function has(string $key): bool
    {
        return $this->providers->has($key);
    }

    /**
     * Resolve a provider instance by key.
     *
     * @throws \InvalidArgumentException
     */
    public function resolve(string $key): TelematicProviderInterface
    {
        $descriptor = $this->findByKey($key);

        if (!$descriptor) {
            throw new \InvalidArgumentException("Provider '{$key}' not found in registry.");
        }

        if (!$descriptor->driverClass) {
            throw new \InvalidArgumentException("Provider '{$key}' does not have a driver class.");
        }

        if (!class_exists($descriptor->driverClass)) {
            throw new \InvalidArgumentException("Provider driver class '{$descriptor->driverClass}' does not exist.");
        }

        $provider = app($descriptor->driverClass);

        if (!$provider instanceof TelematicProviderInterface) {
            throw new \InvalidArgumentException('Provider driver class must implement TelematicProviderInterface.');
        }

        return $provider;
    }

    /**
     * Get providers that support webhooks.
     *
     * @return Collection<TelematicProviderDescriptor>
     */
    public function getWebhookProviders(): Collection
    {
        return $this->providers->filter(fn ($p) => $p->supportsWebhooks);
    }

    /**
     * Get providers that support discovery.
     *
     * @return Collection<TelematicProviderDescriptor>
     */
    public function getDiscoveryProviders(): Collection
    {
        return $this->providers->filter(fn ($p) => $p->supportsDiscovery);
    }

    /**
     * Get native providers only.
     *
     * @return Collection<TelematicProviderDescriptor>
     */
    public function getNativeProviders(): Collection
    {
        return $this->providers->filter(fn ($p) => $p->type === 'native');
    }

    /**
     * Get custom providers only.
     *
     * @return Collection<TelematicProviderDescriptor>
     */
    public function getCustomProviders(): Collection
    {
        return $this->providers->filter(fn ($p) => $p->type === 'custom');
    }
}
