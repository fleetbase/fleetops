<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Illuminate\Http\Request;

/**
 * Subfleet expansion, asserted through the mapping the request actually goes
 * through rather than through the shape of the source file.
 *
 * The public name and the relation differ only in case — `subfleets` against
 * `subFleets` — and the automatic camelCase normalisation upstream leaves
 * `subfleets` untouched, so the documented expansion reached `load('subfleets')`
 * and raised. The resource used to paper over that with a hand-written special
 * case for two nested paths; the mapping handles every path instead.
 */
function fleetopsHierarchyExpansions(array $query): array
{
    $controller = new FleetController();
    $reflection = new ReflectionMethod(FleetController::class, 'resolvePublicExpansions');
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, new Request($query), FleetController::EXPANDABLE);
}

test('fleet subfleet expansion resolves to the relation name eloquent actually has', function () {
    expect(fleetopsHierarchyExpansions(['with' => 'subfleets']))->toBe(['subFleets'])
        ->and(fleetopsHierarchyExpansions(['with' => ['subfleets']]))->toBe(['subFleets'])
        // The camelCase spelling names the same relation.
        ->and(fleetopsHierarchyExpansions(['with' => 'subFleets']))->toBe(['subFleets']);
});

test('fleet resource expands subfleet drivers and vehicles for hierarchy payloads', function () {
    expect(fleetopsHierarchyExpansions(['with' => 'subfleets.drivers']))->toBe(['subFleets.drivers'])
        ->and(fleetopsHierarchyExpansions(['with' => 'subfleets.vehicles']))->toBe(['subFleets.vehicles'])
        ->and(fleetopsHierarchyExpansions(['with' => ['subfleets.drivers', 'subfleets.vehicles']]))
        ->toBe(['subFleets.drivers', 'subFleets.vehicles']);
});

test('fleet expansion refuses a nested path that would recurse or reach an unlisted relation', function () {
    // Nothing under a subfleet re-opens the tree, so an expansion cannot be made
    // to walk the hierarchy indefinitely.
    expect(fleetopsHierarchyExpansions(['with' => 'subfleets.subfleets']))->toBe([])
        ->and(fleetopsHierarchyExpansions(['with' => 'subfleets.drivers.user']))->toBe([])
        ->and(fleetopsHierarchyExpansions(['with' => 'company']))->toBe([]);
});

test('fleet expansion input normalisation handles every malformed shape without raising', function () {
    // Postman and generated clients produce all of these. None may reach
    // Eloquent, and none may raise: the parameter is a convenience, not a
    // reason to fail an otherwise valid request.
    expect(fleetopsHierarchyExpansions(['with' => ['vendor', ['nested', 'array']]]))->toBe(['vendor'])
        ->and(fleetopsHierarchyExpansions(['with' => 'subfleets.']))->toBe([])
        ->and(fleetopsHierarchyExpansions(['with' => '.drivers']))->toBe([])
        ->and(fleetopsHierarchyExpansions(['with' => ' vendor , , zone ']))->toBe(['vendor', 'zone'])
        ->and(fleetopsHierarchyExpansions(['with' => 'vendor,vendor']))->toBe(['vendor'])
        ->and(fleetopsHierarchyExpansions(['with' => '']))->toBe([])
        ->and(fleetopsHierarchyExpansions([]))->toBe([])
        // `expand` is the documented alias and takes over when `with` is empty.
        ->and(fleetopsHierarchyExpansions(['with' => '', 'expand' => 'vendor']))->toBe(['vendor']);
});
