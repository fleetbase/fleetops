<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\VendorController;
use Fleetbase\FleetOps\Http\Requests\CreateVendorRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVendorRequest;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiVendorControllerProbe extends VendorController
{
    public ?Vendor $vendor      = null;
    public array $placeLookups  = [];
    public array $upserts       = [];
    public mixed $queryResults  = null;
    public bool $vendorNotFound = false;

    protected function getPlaceUuid(string $table, array $where): ?string
    {
        $this->placeLookups[] = [$table, $where];

        return 'place-uuid';
    }

    protected function updateOrCreateVendor(array $where, array $input): Vendor
    {
        $this->upserts[] = [$where, $input];

        $vendor = new FleetOpsApiVendorFake();
        $vendor->setRawAttributes(array_merge(['uuid' => 'created-vendor-uuid'], $input));

        return $vendor;
    }

    protected function findVendorRecord(string $id): Vendor
    {
        if ($this->vendorNotFound) {
            throw new ModelNotFoundException();
        }

        $this->vendor?->setAttribute('lookup_id', $id);

        return $this->vendor;
    }

    protected function queryVendors(Request $request)
    {
        $this->queryResults = $this->queryResults ?? [['uuid' => 'vendor-uuid']];

        return $this->queryResults;
    }

    protected function vendorResource(Vendor $vendor)
    {
        return ['resource' => 'vendor', 'vendor' => $vendor];
    }

    protected function vendorResourceCollection($results)
    {
        return ['collection' => 'vendor', 'items' => $results];
    }

    protected function deletedVendorResource(Vendor $vendor)
    {
        return ['resource' => 'deleted-vendor', 'vendor' => $vendor];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiVendorFake extends Vendor
{
    public array $updates       = [];
    public bool $flushedForTest = false;
    public bool $deletedForTest = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function flushAttributesCache(): bool
    {
        $this->flushedForTest = true;

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsCreateVendorRequest(array $input): CreateVendorRequest
{
    return CreateVendorRequest::create('/api/v1/vendors', 'POST', $input);
}

function fleetopsUpdateVendorRequest(array $input): UpdateVendorRequest
{
    return UpdateVendorRequest::create('/api/v1/vendors/vendor-public', 'PUT', $input);
}

test('api vendor controller creates vendors with company context and address lookup', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiVendorControllerProbe();

    $response = $controller->create(fleetopsCreateVendorRequest([
        'name'    => 'Acme Logistics',
        'type'    => 'facilitator',
        'email'   => 'ops@example.test',
        'phone'   => '+6555550000',
        'meta'    => ['tier' => 'gold'],
        'address' => 'place-public',
        'ignored' => 'not copied',
    ]));

    expect($response['resource'])->toBe('vendor')
        ->and($controller->placeLookups)->toBe([
            ['places', ['public_id' => 'place-public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($controller->upserts)->toHaveCount(1)
        ->and($controller->upserts[0][0])->toBe([
            'company_uuid' => 'company-uuid',
            'name'         => 'ACME LOGISTICS',
        ])
        ->and($controller->upserts[0][1])->toMatchArray([
            'name'         => 'Acme Logistics',
            'type'         => 'facilitator',
            'email'        => 'ops@example.test',
            'phone'        => '+6555550000',
            'meta'         => ['tier' => 'gold'],
            'company_uuid' => 'company-uuid',
            'place_uuid'   => 'place-uuid',
        ])
        ->and($controller->upserts[0][1])->not->toHaveKey('ignored');
});

test('api vendor controller updates finds queries and deletes vendors', function () {
    session(['company' => 'company-uuid']);

    $vendor = new FleetOpsApiVendorFake();
    $vendor->setRawAttributes(['uuid' => 'vendor-uuid', 'name' => 'Old Vendor']);

    $controller               = new FleetOpsApiVendorControllerProbe();
    $controller->vendor       = $vendor;
    $controller->queryResults = [['uuid' => 'vendor-a'], ['uuid' => 'vendor-b']];

    $updated = $controller->update('vendor-public', fleetopsUpdateVendorRequest([
        'name'    => 'Updated Vendor',
        'type'    => 'customer',
        'email'   => 'new@example.test',
        'phone'   => '+6555551111',
        'meta'    => ['tier' => 'silver'],
        'address' => 'place-public',
    ]));
    $found   = $controller->find('vendor-public', new Request());
    $query   = $controller->query(new Request(['limit' => 2]));
    $deleted = $controller->delete('vendor-public', new Request());

    expect($updated['resource'])->toBe('vendor')
        ->and($vendor->updates[0])->toMatchArray([
            'name'       => 'Updated Vendor',
            'type'       => 'customer',
            'email'      => 'new@example.test',
            'phone'      => '+6555551111',
            'meta'       => ['tier' => 'silver'],
            'place_uuid' => 'place-uuid',
        ])
        ->and($vendor->flushedForTest)->toBeTrue()
        ->and($found)->toBe(['resource' => 'vendor', 'vendor' => $vendor])
        ->and($query)->toBe(['collection' => 'vendor', 'items' => [['uuid' => 'vendor-a'], ['uuid' => 'vendor-b']]])
        ->and($deleted)->toBe(['resource' => 'deleted-vendor', 'vendor' => $vendor])
        ->and($vendor->deletedForTest)->toBeTrue();
});

test('api vendor controller returns missing vendor responses for update find and delete', function () {
    $controller                 = new FleetOpsApiVendorControllerProbe();
    $controller->vendorNotFound = true;

    $expected = [
        'json'   => ['error' => 'Vendor resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-vendor', fleetopsUpdateVendorRequest(['name' => 'Missing'])))->toBe($expected)
        ->and($controller->find('missing-vendor', new Request()))->toBe($expected)
        ->and($controller->delete('missing-vendor', new Request()))->toBe($expected);
});
