<?php

namespace Fleetbase\FleetOps\Orchestration\Contracts;

use Illuminate\Support\Collection;

/**
 * OrchestrationEngineInterface.
 *
 * Defines the single contract that every orchestration/optimization engine must
 * implement. The interface is intentionally minimal — engines receive a
 * normalized set of jobs and vehicles and return a normalized result array.
 * All engine-specific payload construction and response parsing is handled
 * inside the concrete engine implementation, not by the caller.
 *
 * Third-party engines can be registered into the OrchestrationEngineRegistry
 * from any Laravel service provider:
 *
 *   app(OrchestrationEngineRegistry::class)->register(new MyEngine());
 *
 * The registered engine will then appear in the FleetOps orchestrator settings
 * dropdown automatically.
 */
interface OrchestrationEngineInterface
{
    /**
     * Run the allocation/optimization algorithm.
     *
     * @param Collection $orders   collection of Order models to allocate
     * @param Collection $vehicles collection of Vehicle models (with loaded driver)
     * @param array      $options  Engine-specific options (e.g. max_travel_time,
     *                             balance_workload, geometry). Keys are defined
     *                             per-engine; unknown keys MUST be silently ignored.
     *
     * @return array{
     *     assignments: array<array{order_id: string, vehicle_id: string, driver_id: string, sequence: int}>,
     *     unassigned:  array<string>,
     *     summary:     array<string, mixed>
     * }
     *
     * The returned array MUST contain:
     *   - assignments: ordered list of {order_id, vehicle_id, driver_id, sequence}
     *   - unassigned:  list of order public_ids that could not be assigned
     *   - summary:     engine-specific metadata (duration, distance, cost, etc.)
     */
    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array;

    /**
     * Return the human-readable display name for this engine.
     * Used in the settings UI dropdown.
     *
     * @return string e.g. "VROOM", "Google OR-Tools", "Custom Solver"
     */
    public function getName(): string;

    /**
     * Return the machine-readable identifier for this engine.
     * Must be unique across all registered engines.
     *
     * @return string e.g. "vroom", "or-tools", "custom"
     */
    public function getIdentifier(): string;
}
