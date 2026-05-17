<?php

namespace Fleetbase\FleetOps\Orchestration\Engines;

use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Illuminate\Support\Collection;

/**
 * CapacityAllocationEngine.
 *
 * Deterministic built-in engine for clients who need feasibility allocation
 * by capacity, skills, and task limits without route geometry or vehicle GPS.
 */
class CapacityAllocationEngine implements OrchestrationEngineInterface
{
    public function getName(): string
    {
        return 'Capacity (built-in)';
    }

    public function getIdentifier(): string
    {
        return 'capacity';
    }

    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $respectCapacity = (bool) ($options['respect_capacity'] ?? true);
        $respectSkills   = (bool) ($options['respect_skills'] ?? true);
        $balanceWorkload = (bool) ($options['balance_workload'] ?? false);

        $tasks       = OrchestrationPayloadBuilder::buildCapacityTasks($orders);
        $vehiclePool = $this->buildVehiclePool(OrchestrationPayloadBuilder::buildCapacityVehicles($vehicles));

        $assignments = [];
        $unassigned  = [];
        $reasons     = [];

        foreach ($this->sortTasks($tasks) as $task) {
            if (!empty($task['invalid'])) {
                $this->markUnassigned($task['id'], $task['reason'] ?? 'invalid_task', $unassigned, $reasons);
                continue;
            }

            $bestIndex = $this->findVehicleIndex($vehiclePool, $task, $respectCapacity, $respectSkills, $balanceWorkload);
            if ($bestIndex === null) {
                $this->markUnassigned($task['id'], $this->resolveFailureReason($vehiclePool, $task, $respectCapacity, $respectSkills), $unassigned, $reasons);
                continue;
            }

            $sequence = ++$vehiclePool[$bestIndex]['assigned'];
            $this->consumeCapacity($vehiclePool[$bestIndex]['remaining'], $task['amount'] ?? [0, 0, 0, 0]);

            $assignments[] = [
                'order_id'   => $task['id'],
                'vehicle_id' => $vehiclePool[$bestIndex]['id'],
                'driver_id'  => $vehiclePool[$bestIndex]['driver_id'],
                'sequence'   => $sequence,
                'arrival'    => null,
                'duration'   => null,
                'distance'   => null,
            ];
        }

        return [
            'assignments' => $assignments,
            'unassigned'  => array_values(array_unique($unassigned)),
            'summary'     => [
                'engine'               => 'capacity',
                'allocation_strategy'  => 'capacity_only',
                'assigned'             => count($assignments),
                'unassigned'           => count(array_unique($unassigned)),
                'unassigned_reasons'   => $reasons,
                'respect_capacity'     => $respectCapacity,
                'respect_skills'       => $respectSkills,
                'balance_workload'     => $balanceWorkload,
            ],
        ];
    }

    protected function buildVehiclePool(array $vehicles): array
    {
        return array_map(fn (array $vehicle) => [
            'id'        => $vehicle['id'],
            'driver_id' => $vehicle['driver_id'] ?? null,
            'capacity'  => $this->normalizeVector($vehicle['capacity'] ?? [0, 0, 0, 0]),
            'remaining' => $this->normalizeVector($vehicle['capacity'] ?? [0, 0, 0, 0]),
            'skills'    => $vehicle['skills'] ?? [],
            'max_tasks' => $vehicle['max_tasks'] ?? null,
            'assigned'  => 0,
        ], $vehicles);
    }

    protected function sortTasks(array $tasks): array
    {
        usort($tasks, fn (array $a, array $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $tasks;
    }

    protected function findVehicleIndex(array $vehiclePool, array $task, bool $respectCapacity, bool $respectSkills, bool $balanceWorkload): ?int
    {
        $eligible = [];

        foreach ($vehiclePool as $index => $vehicle) {
            if (!$this->isVehicleFeasible($vehicle, $task, $respectCapacity, $respectSkills)) {
                continue;
            }

            $eligible[] = $index;
        }

        if (empty($eligible)) {
            return null;
        }

        if (!$balanceWorkload) {
            return $eligible[0];
        }

        usort($eligible, function (int $a, int $b) use ($vehiclePool) {
            $assignedCompare = $vehiclePool[$a]['assigned'] <=> $vehiclePool[$b]['assigned'];
            if ($assignedCompare !== 0) {
                return $assignedCompare;
            }

            return array_sum($vehiclePool[$b]['remaining']) <=> array_sum($vehiclePool[$a]['remaining']);
        });

        return $eligible[0];
    }

    protected function isVehicleFeasible(array $vehicle, array $task, bool $respectCapacity, bool $respectSkills): bool
    {
        if ($vehicle['max_tasks'] !== null && $vehicle['assigned'] >= $vehicle['max_tasks']) {
            return false;
        }

        if ($respectSkills && !$this->hasRequiredSkills($vehicle['skills'], $task['skills'] ?? [])) {
            return false;
        }

        if ($respectCapacity && !$this->hasCapacity($vehicle['remaining'], $task['amount'] ?? [0, 0, 0, 0])) {
            return false;
        }

        return true;
    }

    protected function hasRequiredSkills(array $vehicleSkills, array $requiredSkills): bool
    {
        if (empty($requiredSkills)) {
            return true;
        }

        return empty(array_diff($requiredSkills, $vehicleSkills));
    }

    protected function hasCapacity(array $remaining, array $demand): bool
    {
        foreach ($this->normalizeVector($demand) as $index => $amount) {
            if ($amount > ($remaining[$index] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    protected function consumeCapacity(array &$remaining, array $demand): void
    {
        foreach ($this->normalizeVector($demand) as $index => $amount) {
            $remaining[$index] = max(0, ($remaining[$index] ?? 0) - $amount);
        }
    }

    protected function resolveFailureReason(array $vehiclePool, array $task, bool $respectCapacity, bool $respectSkills): string
    {
        if (empty($vehiclePool)) {
            return 'no_available_vehicle';
        }

        $capacityEligible = false;
        $skillsEligible   = false;
        $taskEligible     = false;

        foreach ($vehiclePool as $vehicle) {
            $capacityEligible = $capacityEligible || !$respectCapacity || $this->hasCapacity($vehicle['remaining'], $task['amount'] ?? [0, 0, 0, 0]);
            $skillsEligible   = $skillsEligible || !$respectSkills || $this->hasRequiredSkills($vehicle['skills'], $task['skills'] ?? []);
            $taskEligible     = $taskEligible || $vehicle['max_tasks'] === null || $vehicle['assigned'] < $vehicle['max_tasks'];
        }

        if (!$capacityEligible) {
            return 'capacity_exceeded';
        }
        if (!$skillsEligible) {
            return 'missing_required_skills';
        }
        if (!$taskEligible) {
            return 'max_tasks_exceeded';
        }

        return 'no_available_vehicle';
    }

    protected function normalizeVector(array $vector): array
    {
        return array_pad(array_map(fn ($value) => max(0, (int) round((float) $value)), array_values($vector)), 4, 0);
    }

    protected function markUnassigned(string $orderId, string $reason, array &$unassigned, array &$reasons): void
    {
        $unassigned[] = $orderId;
        $reasons[]    = [
            'order_id' => $orderId,
            'reason'   => $reason,
        ];
    }
}
