<?php

namespace Fleetbase\FleetOps\Orchestration;

use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;

/**
 * OrchestrationEngineRegistry.
 *
 * A simple service-locator for orchestration engines. Engines register
 * themselves (typically from a service provider) and the
 * OrchestrationController resolves the active engine at runtime from the
 * FleetOps setting key 'fleetops.orchestrator_engine'.
 *
 * Third-party extensions can register their own engines by resolving this
 * registry in their service provider:
 *
 *   $this->app->resolving(OrchestrationEngineRegistry::class, function ($registry) {
 *       $registry->register(new MyCustomEngine());
 *   });
 */
class OrchestrationEngineRegistry
{
    /**
     * Registered engines keyed by their identifier.
     *
     * @var array<string, OrchestrationEngineInterface>
     */
    protected array $engines = [];

    /**
     * Register an orchestration engine.
     *
     * @throws \InvalidArgumentException if an engine with the same identifier is already registered
     */
    public function register(OrchestrationEngineInterface $engine): void
    {
        $id = $engine->getIdentifier();
        if (isset($this->engines[$id])) {
            throw new \InvalidArgumentException("An orchestration engine with identifier '{$id}' is already registered.");
        }
        $this->engines[$id] = $engine;
    }

    /**
     * Resolve an engine by identifier.
     *
     * @throws \RuntimeException if no engine with the given identifier is registered
     */
    public function resolve(string $identifier): OrchestrationEngineInterface
    {
        if (!isset($this->engines[$identifier])) {
            throw new \RuntimeException("No orchestration engine registered with identifier '{$identifier}'. " . 'Available engines: ' . implode(', ', array_keys($this->engines)));
        }

        return $this->engines[$identifier];
    }

    /**
     * Return all registered engines as an array of {id, name} pairs.
     * Used by the settings API to populate the engine selector dropdown.
     *
     * @return array<array{id: string, name: string}>
     */
    public function available(): array
    {
        return array_values(array_map(
            fn (OrchestrationEngineInterface $engine) => [
                'id'   => $engine->getIdentifier(),
                'name' => $engine->getName(),
            ],
            $this->engines
        ));
    }

    /**
     * Check whether an engine with the given identifier is registered.
     */
    public function has(string $identifier): bool
    {
        return isset($this->engines[$identifier]);
    }
}
