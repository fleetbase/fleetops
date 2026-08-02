<?php

use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Illuminate\Support\Collection;

class FleetOpsRegistryEngineFake implements OrchestrationEngineInterface
{
    public function __construct(private string $identifier, private string $name)
    {
    }

    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        return [
            'assignments' => [],
            'unassigned'  => [],
            'summary'     => [],
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}

test('orchestration engine registry rejects duplicate and missing identifiers', function () {
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new FleetOpsRegistryEngineFake('primary', 'Primary Engine'));

    expect($registry->has('primary'))->toBeTrue()
        ->and($registry->available())->toBe([
            ['id' => 'primary', 'name' => 'Primary Engine'],
        ])
        ->and(fn () => $registry->register(new FleetOpsRegistryEngineFake('primary', 'Duplicate Engine')))
        ->toThrow(InvalidArgumentException::class, "An orchestration engine with identifier 'primary' is already registered.")
        ->and(fn () => $registry->resolve('missing'))
        ->toThrow(RuntimeException::class, "No orchestration engine registered with identifier 'missing'. Available engines: primary");
});
