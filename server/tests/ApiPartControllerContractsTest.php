<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PartController;
use Fleetbase\FleetOps\Http\Requests\CreatePartRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePartRequest;
use Fleetbase\FleetOps\Models\Part;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiPartControllerProbe extends PartController
{
    public ?Part $part           = null;
    public array $createdParts   = [];
    public array $resolvedUuids  = [];
    public array $resolvedMorphs = [];
    public mixed $queryResults   = null;
    public bool $partNotFound    = false;

    protected function createPart(array $input): Part
    {
        $this->createdParts[] = $input;

        $part = new FleetOpsApiPartFake();
        $part->setRawAttributes(array_merge(['uuid' => 'created-part-uuid'], $input));

        return $part;
    }

    protected function resolveModel(string $modelClass, string $id): Illuminate\Database\Eloquent\Model
    {
        if ($this->partNotFound) {
            throw new ModelNotFoundException();
        }

        $this->part?->setAttribute('lookup_id', $id);

        return $this->part;
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

    protected function queryParts(Request $request, callable $callback)
    {
        $query = new FleetOpsApiPartQueryFake();
        $callback($query);

        $this->queryResults                = $this->queryResults ?? [['uuid' => 'part-uuid']];
        $this->queryResults['query_calls'] = $query->calls;

        return $this->queryResults;
    }

    protected function partResource(Part $part)
    {
        return ['resource' => 'part', 'part' => $part];
    }

    protected function partResourceCollection($results)
    {
        return ['collection' => 'part', 'items' => $results];
    }

    protected function deletedPartResource(Part $part)
    {
        return ['resource' => 'deleted-part', 'part' => $part];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiPartFake extends Part
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

class FleetOpsApiPartQueryFake
{
    public array $calls = [];

    public function with(array $relations): void
    {
        $this->calls[] = ['with', $relations];
    }
}

function fleetopsCreatePartRequest(array $input): CreatePartRequest
{
    return CreatePartRequest::create('/api/v1/parts', 'POST', $input);
}

function fleetopsUpdatePartRequest(array $input): UpdatePartRequest
{
    return UpdatePartRequest::create('/api/v1/parts/part-public', 'PUT', $input);
}

test('api part controller creates parts with public relation and morph resolution', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiPartControllerProbe();

    $response = $controller->create(fleetopsCreatePartRequest([
        'sku'              => 'SKU-1',
        'name'             => 'Brake Pad',
        'manufacturer'     => 'Acme',
        'model'            => 'BP-100',
        'serial_number'    => 'SER-1',
        'barcode'          => 'BAR-1',
        'description'      => 'Front brake pad',
        'quantity_on_hand' => 12,
        'unit_cost'        => 15.5,
        'msrp'             => 25,
        'currency'         => 'USD',
        'type'             => 'consumable',
        'status'           => 'active',
        'specs'            => ['axle' => 'front'],
        'meta'             => ['source' => 'api'],
        'vendor'           => 'vendor-public',
        'warranty'         => 'warranty-public',
        'photo'            => '',
        'asset_type'       => 'vehicle',
        'asset'            => 'vehicle-public',
        'ignored'          => 'not copied',
    ]));

    expect($response['resource'])->toBe('part')
        ->and($controller->createdParts)->toHaveCount(1)
        ->and($controller->createdParts[0])->toMatchArray([
            'sku'              => 'SKU-1',
            'name'             => 'Brake Pad',
            'manufacturer'     => 'Acme',
            'model'            => 'BP-100',
            'serial_number'    => 'SER-1',
            'barcode'          => 'BAR-1',
            'description'      => 'Front brake pad',
            'quantity_on_hand' => 12,
            'unit_cost'        => 15.5,
            'msrp'             => 25,
            'currency'         => 'USD',
            'type'             => 'consumable',
            'status'           => 'active',
            'specs'            => ['axle' => 'front'],
            'meta'             => ['source' => 'api'],
            'company_uuid'     => 'company-uuid',
            'vendor_uuid'      => 'vendor-public-uuid',
            'warranty_uuid'    => 'warranty-public-uuid',
            'photo_uuid'       => null,
            'asset_type'       => 'resolved-vehicle',
            'asset_uuid'       => 'vehicle-public-uuid',
        ])
        ->and($controller->createdParts[0])->not->toHaveKey('ignored')
        ->and($response['part']->loads)->toBe([['vendor', 'warranty', 'photo', 'asset']]);
});

test('api part controller updates finds queries and deletes parts', function () {
    $part = new FleetOpsApiPartFake();
    $part->setRawAttributes(['uuid' => 'part-uuid', 'name' => 'Old Part']);

    $controller               = new FleetOpsApiPartControllerProbe();
    $controller->part         = $part;
    $controller->queryResults = [['uuid' => 'part-a'], ['uuid' => 'part-b']];

    $updated = $controller->update('part-public', fleetopsUpdatePartRequest([
        'name'       => 'Updated Part',
        'vendor'     => 'vendor-public',
        'warranty'   => '',
        'photo'      => 'photo-public',
        'asset'      => '',
        'asset_type' => 'vehicle',
    ]));
    $found   = $controller->find('part-public');
    $query   = $controller->query(new Request(['limit' => 2]));
    $deleted = $controller->delete('part-public');

    expect($updated['resource'])->toBe('part')
        ->and($part->updates[0])->toMatchArray([
            'name'          => 'Updated Part',
            'vendor_uuid'   => 'vendor-public-uuid',
            'warranty_uuid' => null,
            'photo_uuid'    => 'photo-public-uuid',
            'asset_type'    => null,
            'asset_uuid'    => null,
        ])
        ->and($part->refreshedForTest)->toBeTrue()
        ->and($part->loads)->toContain(['vendor', 'warranty', 'photo', 'asset'])
        ->and($found)->toBe(['resource' => 'part', 'part' => $part])
        ->and($query['collection'])->toBe('part')
        ->and($query['items']['query_calls'])->toBe([['with', ['vendor', 'warranty', 'photo', 'asset']]])
        ->and($deleted)->toBe(['resource' => 'deleted-part', 'part' => $part])
        ->and($part->deletedForTest)->toBeTrue();
});

test('api part controller returns missing part responses for update find and delete', function () {
    $controller               = new FleetOpsApiPartControllerProbe();
    $controller->partNotFound = true;

    $expected = [
        'json'   => ['error' => 'Part resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-part', fleetopsUpdatePartRequest(['name' => 'Missing'])))->toBe($expected)
        ->and($controller->find('missing-part'))->toBe($expected)
        ->and($controller->delete('missing-part'))->toBe($expected);
});
