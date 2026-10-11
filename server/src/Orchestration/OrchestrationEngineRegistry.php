<?php

namespace Fleetbase\FleetOps\Orchestration;

use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Illuminate\Support\Collection;

/**
 * OrchestrationEngineRegistry.
 *
 * A simple service-locator for orchestration engines. Engines register
 * themselves (typically from a service provider) and the
 * OrchestrationController resolves the active engine at runtime from the
 * organization's Orchestrator settings (see OrchestratorSettings).
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
     * Built-in engine that runs when the selected engine is missing or unavailable.
     */
    public const FALLBACK_ENGINE = 'greedy';

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

    /**
     * Run the selected engine, falling back to the built-in greedy engine when
     * the selected engine is not registered or reports itself unavailable
     * (a RuntimeException, e.g. VROOM unreachable or misconfigured).
     *
     * The result's summary always names the engine that produced it. A
     * fallback result also carries `requested_engine` and `fallback_reason`
     * so callers can tell the user the plan did not come from their engine.
     *
     * @throws \RuntimeException when the selected engine fails and there is no fallback to run
     */
    public function allocateWithFallback(string $identifier, Collection $orders, Collection $vehicles, array $options = []): array
    {
        try {
            $engine            = $this->resolve($identifier);
            $result            = $engine->allocate($orders, $vehicles, $options);
            $result['summary'] = array_merge(['engine' => $engine->getIdentifier()], $result['summary'] ?? []);

            return $result;
        } catch (\RuntimeException $e) {
            if ($identifier === static::FALLBACK_ENGINE || !$this->has(static::FALLBACK_ENGINE)) {
                throw $e;
            }

            // The selected solvers build multi-stop routes, so the fallback
            // must too or orders would be dropped when vehicles are scarce.
            $result = $this->resolve(static::FALLBACK_ENGINE)->allocate($orders, $vehicles, array_merge(['allow_multi_order' => true], $options));

            return static::markFallback($result, static::FALLBACK_ENGINE, $identifier, $e->getMessage());
        }
    }

    /**
     * Record on a result that it came from a different engine than requested, and why.
     *
     * @param bool $warn whether the substitution is a failure the user should be warned about,
     *                   rather than the expected behaviour for the requested engine
     */
    public static function markFallback(array $result, string $engine, string $requestedEngine, string $reason, bool $warn = true): array
    {
        $result['summary'] = array_merge($result['summary'] ?? [], [
            'engine'           => $engine,
            'requested_engine' => $requestedEngine,
            'fallback_reason'  => $reason,
        ]);
        if (!$warn) {
            return $result;
        }

        $result['warning'] = sprintf('The "%s" engine was used instead of "%s": %s', $engine, $requestedEngine, $reason);

        return $result;
    }
}
