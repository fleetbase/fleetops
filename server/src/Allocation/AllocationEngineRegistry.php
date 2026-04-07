<?php

namespace Fleetbase\FleetOps\Allocation;

use Fleetbase\FleetOps\Allocation\Contracts\AllocationEngineInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * AllocationEngineRegistry
 *
 * A simple service-locator for allocation engines. Engines register themselves
 * (typically from a service provider or instance initializer equivalent) and
 * the AllocationController resolves the active engine at runtime from the
 * FleetOps setting key 'fleetops.allocation_engine'.
 *
 * This pattern is identical to how OSRM registers itself in the frontend
 * route-optimization registry — the registry itself has no knowledge of any
 * specific engine.
 *
 * @example Registering a custom engine from a service provider:
 *   $this->app->resolving(AllocationEngineRegistry::class, function ($registry) {
 *       $registry->register(new MyCustomAllocationEngine());
 *   });
 */
class AllocationEngineRegistry
{
    /**
     * Registered engines keyed by their identifier.
     *
     * @var array<string, AllocationEngineInterface>
     */
    protected array $engines = [];

    /**
     * Register an allocation engine.
     *
     * @throws InvalidArgumentException if an engine with the same identifier is already registered.
     */
    public function register(AllocationEngineInterface $engine): void
    {
        $id = $engine->getIdentifier();

        if (isset($this->engines[$id])) {
            throw new InvalidArgumentException("An allocation engine with identifier '{$id}' is already registered.");
        }

        $this->engines[$id] = $engine;
    }

    /**
     * Resolve an engine by identifier.
     *
     * @throws RuntimeException if no engine with the given identifier is registered.
     */
    public function resolve(string $identifier): AllocationEngineInterface
    {
        if (!isset($this->engines[$identifier])) {
            throw new RuntimeException(
                "No allocation engine registered with identifier '{$identifier}'. " .
                'Available engines: ' . implode(', ', array_keys($this->engines))
            );
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
            fn (AllocationEngineInterface $engine) => [
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
