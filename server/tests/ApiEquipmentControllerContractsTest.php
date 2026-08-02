<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\EquipmentController;
use Fleetbase\FleetOps\Http\Requests\CreateEquipmentRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateEquipmentRequest;
use Fleetbase\FleetOps\Models\Equipment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiEquipmentControllerProbe extends EquipmentController
{
    public ?Equipment $equipment     = null;
    public array $createdEquipment   = [];
    public array $resolvedUuids      = [];
    public array $resolvedMorphs     = [];
    public mixed $queryResults       = null;
    public bool $equipmentNotFound   = false;

    protected function createEquipment(array $input): Equipment
    {
        $this->createdEquipment[] = $input;

        $equipment = new FleetOpsApiEquipmentFake();
        $equipment->setRawAttributes(array_merge(['uuid' => 'created-equipment-uuid'], $input));

        return $equipment;
    }

    protected function resolveModel(string $modelClass, string $id): Illuminate\Database\Eloquent\Model
    {
        if ($this->equipmentNotFound) {
            throw new ModelNotFoundException();
        }

        $this->equipment?->setAttribute('lookup_id', $id);

        return $this->equipment;
    }

    protected function resolveUuid(string $modelClass, ?string $id): ?string
    {
        $this->resolvedUuids[] = [$modelClass, $id];

        return $id ? $id . '-uuid' : null;
    }

    protected function resolveMorph(?string $type, ?string $id): array
    {
        $this->resolvedMorphs[] = [$type, $id];

        return [$type ? 'resolved-' . $type : null, $id ? $id . '-uuid' : null];
    }

    protected function queryEquipment(Request $request, callable $callback)
    {
        $query = new FleetOpsApiEquipmentQueryFake();
        $callback($query);

        $this->queryResults                = $this->queryResults ?? [['uuid' => 'equipment-uuid']];
        $this->queryResults['query_calls'] = $query->calls;

        return $this->queryResults;
    }

    protected function equipmentResource(Equipment $equipment)
    {
        return ['resource' => 'equipment', 'equipment' => $equipment];
    }

    protected function equipmentResourceCollection($results)
    {
        return ['collection' => 'equipment', 'items' => $results];
    }

    protected function deletedEquipmentResource(Equipment $equipment)
    {
        return ['resource' => 'deleted-equipment', 'equipment' => $equipment];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiEquipmentFake extends Equipment
{
    public array $loads           = [];
    public array $updates         = [];
    public bool $refreshedForTest = false;
    public bool $deletedForTest   = false;

    public function load($relations)
    {
        $this->loads[] = $relations;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function refresh()
    {
        $this->refreshedForTest = true;

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiEquipmentQueryFake
{
    public array $calls = [];

    public function with(array $relations): void
    {
        $this->calls[] = ['with', $relations];
    }
}

function fleetopsCreateEquipmentRequest(array $input): CreateEquipmentRequest
{
    return CreateEquipmentRequest::create('/api/v1/equipment', 'POST', $input);
}

function fleetopsUpdateEquipmentRequest(array $input): UpdateEquipmentRequest
{
    return UpdateEquipmentRequest::create('/api/v1/equipment/equipment-public', 'PUT', $input);
}

test('api equipment controller creates equipment with public relation and morph resolution', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiEquipmentControllerProbe();

    $response = $controller->create(fleetopsCreateEquipmentRequest([
        'name'           => 'Lift Gate',
        'code'           => 'LIFT-1',
        'type'           => 'attachment',
        'status'         => 'available',
        'serial_number'  => 'SER-9',
        'manufacturer'   => 'Acme',
        'model'          => 'LG-100',
        'purchased_at'   => '2026-01-15',
        'purchase_price' => 4500,
        'currency'       => 'USD',
        'meta'           => ['mounted' => true],
        'warranty'       => 'warranty-public',
        'photo'          => '',
        'equipable_type' => 'vehicle',
        'equipable'      => 'vehicle-public',
        'ignored'        => 'not copied',
    ]));

    expect($response['resource'])->toBe('equipment')
        ->and($controller->createdEquipment)->toHaveCount(1)
        ->and($controller->createdEquipment[0])->toMatchArray([
            'name'           => 'Lift Gate',
            'code'           => 'LIFT-1',
            'type'           => 'attachment',
            'status'         => 'available',
            'serial_number'  => 'SER-9',
            'manufacturer'   => 'Acme',
            'model'          => 'LG-100',
            'purchased_at'   => '2026-01-15',
            'purchase_price' => 4500,
            'currency'       => 'USD',
            'meta'           => ['mounted' => true],
            'company_uuid'   => 'company-uuid',
            'warranty_uuid'  => 'warranty-public-uuid',
            'photo_uuid'     => null,
            'equipable_type' => 'resolved-vehicle',
            'equipable_uuid' => 'vehicle-public-uuid',
        ])
        ->and($controller->createdEquipment[0])->not->toHaveKey('ignored')
        ->and($response['equipment']->loads)->toBe([['warranty', 'photo', 'equipable']]);
});

test('api equipment controller updates finds queries and deletes equipment', function () {
    $equipment = new FleetOpsApiEquipmentFake();
    $equipment->setRawAttributes(['uuid' => 'equipment-uuid', 'name' => 'Old Equipment']);

    $controller                  = new FleetOpsApiEquipmentControllerProbe();
    $controller->equipment       = $equipment;
    $controller->queryResults    = [['uuid' => 'equipment-a'], ['uuid' => 'equipment-b']];

    $updated = $controller->update('equipment-public', fleetopsUpdateEquipmentRequest([
        'name'           => 'Updated Equipment',
        'warranty'       => '',
        'photo'          => 'photo-public',
        'equipable'      => '',
        'equipable_type' => 'vehicle',
    ]));
    $found   = $controller->find('equipment-public');
    $query   = $controller->query(new Request(['limit' => 2]));
    $deleted = $controller->delete('equipment-public');

    expect($updated['resource'])->toBe('equipment')
        ->and($equipment->updates[0])->toMatchArray([
            'name'           => 'Updated Equipment',
            'warranty_uuid'  => null,
            'photo_uuid'     => 'photo-public-uuid',
            'equipable_type' => null,
            'equipable_uuid' => null,
        ])
        ->and($equipment->refreshedForTest)->toBeTrue()
        ->and($equipment->loads)->toContain(['warranty', 'photo', 'equipable'])
        ->and($found)->toBe(['resource' => 'equipment', 'equipment' => $equipment])
        ->and($query['collection'])->toBe('equipment')
        ->and($query['items']['query_calls'])->toBe([['with', ['warranty', 'photo', 'equipable']]])
        ->and($deleted)->toBe(['resource' => 'deleted-equipment', 'equipment' => $equipment])
        ->and($equipment->deletedForTest)->toBeTrue();
});

test('api equipment controller returns missing equipment responses for update find and delete', function () {
    $controller                    = new FleetOpsApiEquipmentControllerProbe();
    $controller->equipmentNotFound = true;

    $expected = [
        'json'   => ['error' => 'Equipment resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-equipment', fleetopsUpdateEquipmentRequest(['name' => 'Missing'])))->toBe($expected)
        ->and($controller->find('missing-equipment'))->toBe($expected)
        ->and($controller->delete('missing-equipment'))->toBe($expected);
});
