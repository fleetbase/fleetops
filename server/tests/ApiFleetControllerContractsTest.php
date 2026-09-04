<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Fleetbase\FleetOps\Http\Requests\CreateFleetRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFleetRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiFleetControllerProbe extends FleetController
{
    public ?Fleet $fleet          = null;
    public ?Vehicle $vehicle      = null;
    public ?Driver $driver        = null;
    public array $createdFleets   = [];
    public array $relationLookups = [];
    public array $unresolvable    = [];
    public array $parentChain     = [];
    public array $membershipCalls = [];
    public mixed $queryResults    = null;
    public bool $fleetNotFound    = false;
    public bool $resourceNotFound = false;

    public function inputForTest(Request $request): array
    {
        return $this->fleetInputFromRequest($request);
    }

    /**
     * Stand in for the company-scoped public-id lookup.
     *
     * Anything listed in `$unresolvable` behaves as a cross-company or missing
     * identifier does in production: nothing is found inside the caller's own
     * company, and the lookup raises.
     */
    protected function resolveUuid(string $modelClass, ?string $id, ?string $companyUuid = null): ?string
    {
        if (empty($id)) {
            return null;
        }

        $this->relationLookups[] = [$modelClass, $id];

        if (in_array($id, $this->unresolvable, true)) {
            throw (new ModelNotFoundException())->setModel($modelClass, $id);
        }

        return $id . '-uuid';
    }

    protected function parentUuidOf(string $uuid): ?string
    {
        return $this->parentChain[$uuid] ?? null;
    }

    protected function createFleet(array $input): Fleet
    {
        $this->createdFleets[] = $input;

        $fleet = new FleetOpsApiFleetFake();
        $fleet->setRawAttributes(array_merge(['uuid' => 'created-fleet-uuid', 'public_id' => 'fleet_created'], $input));

        return $fleet;
    }

    protected function findFleet(string $id): Fleet
    {
        if ($this->fleetNotFound) {
            throw new ModelNotFoundException();
        }

        $this->fleet?->setAttribute('lookup_id', $id);

        return $this->fleet;
    }

    protected function findVehicle(string $id): Vehicle
    {
        if ($this->resourceNotFound) {
            throw new ModelNotFoundException();
        }

        return $this->vehicle;
    }

    protected function findDriver(string $id): Driver
    {
        if ($this->resourceNotFound) {
            throw new ModelNotFoundException();
        }

        return $this->driver;
    }

    protected function withPublicRelations(Fleet $fleet): Fleet
    {
        return $fleet;
    }

    protected function assignVehicleToFleet(Fleet $fleet, Vehicle $vehicle): void
    {
        $this->membershipCalls[] = ['assign-vehicle', $fleet->public_id, $vehicle->public_id];
    }

    protected function removeVehicleFromFleet(Fleet $fleet, Vehicle $vehicle): void
    {
        $this->membershipCalls[] = ['remove-vehicle', $fleet->public_id, $vehicle->public_id];
    }

    protected function assignDriverToFleet(Fleet $fleet, Driver $driver): void
    {
        $this->membershipCalls[] = ['assign-driver', $fleet->public_id, $driver->public_id];
    }

    protected function removeDriverFromFleet(Fleet $fleet, Driver $driver): void
    {
        $this->membershipCalls[] = ['remove-driver', $fleet->public_id, $driver->public_id];
    }

    protected function queryFleets(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'fleet-uuid']];
    }

    protected function fleetResource(Fleet $fleet)
    {
        return ['resource' => 'fleet', 'fleet' => $fleet];
    }

    protected function fleetResourceCollection($results)
    {
        return ['collection' => 'fleet', 'items' => $results];
    }

    protected function deletedFleetResource(Fleet $fleet)
    {
        return ['resource' => 'deleted-fleet', 'fleet' => $fleet];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiFleetFake extends Fleet
{
    public array $updates       = [];
    public bool $deletedForTest = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiFleetVehicleFake extends Vehicle
{
}

class FleetOpsApiFleetDriverFake extends Driver
{
}

function fleetopsCreateFleetRequest(array $input): CreateFleetRequest
{
    return CreateFleetRequest::create('/api/v1/fleets', 'POST', $input);
}

function fleetopsUpdateFleetRequest(array $input): UpdateFleetRequest
{
    return UpdateFleetRequest::create('/api/v1/fleets/fleet-public', 'PUT', $input);
}

function fleetopsFleetFake(array $attributes): FleetOpsApiFleetFake
{
    $fleet = new FleetOpsApiFleetFake();
    $fleet->setRawAttributes($attributes);

    return $fleet;
}

test('api fleet controller creates fleets with service area resolution', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiFleetControllerProbe();

    $response = $controller->create(fleetopsCreateFleetRequest([
        'name'         => 'Downtown Fleet',
        'service_area' => 'service-area-public',
        'ignored'      => 'not copied',
    ]));

    // The pre-expansion contract — name plus service area — still behaves the
    // way clients written against it expect.
    expect($response['resource'])->toBe('fleet')
        ->and($controller->relationLookups)->toBe([
            [ServiceArea::class, 'service-area-public'],
        ])
        ->and($controller->createdFleets[0])->toBe([
            'name'              => 'Downtown Fleet',
            'service_area_uuid' => 'service-area-public-uuid',
            'company_uuid'      => 'company-uuid',
        ]);
});

test('api fleet controller accepts every safe fleet field and resolves each relationship', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiFleetControllerProbe();

    $controller->create(fleetopsCreateFleetRequest([
        'name'         => 'Carpool',
        'color'        => '#2563EB',
        'task'         => 'Employee transport',
        'status'       => 'active',
        'parent_fleet' => 'fleet_parent123',
        'vendor'       => 'vendor_123',
        'service_area' => 'service_area_123',
        'zone'         => 'zone_123',
        'photo'        => 'file_123',
    ]));

    expect($controller->createdFleets[0])->toBe([
        'name'              => 'Carpool',
        'color'             => '#2563EB',
        'task'              => 'Employee transport',
        'status'            => 'active',
        'service_area_uuid' => 'service_area_123-uuid',
        'zone_uuid'         => 'zone_123-uuid',
        'vendor_uuid'       => 'vendor_123-uuid',
        'parent_fleet_uuid' => 'fleet_parent123-uuid',
        'image_uuid'        => 'file_123-uuid',
        'company_uuid'      => 'company-uuid',
    ])->and($controller->relationLookups)->toBe([
        [ServiceArea::class, 'service_area_123'],
        [Zone::class, 'zone_123'],
        [Vendor::class, 'vendor_123'],
        [Fleet::class, 'fleet_parent123'],
        [File::class, 'file_123'],
    ]);
});

test('api fleet controller input excludes tenancy raw relation and generated columns', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiFleetControllerProbe();

    $input = $controller->inputForTest(new Request([
        'name'              => 'Carpool',
        'company_uuid'      => 'someone-elses-company',
        'uuid'              => 'forged',
        '_key'              => 'forged',
        'public_id'         => 'fleet_forged',
        'slug'              => 'forged',
        'service_area_uuid' => 'forged',
        'zone_uuid'         => 'forged',
        'vendor_uuid'       => 'forged',
        'parent_fleet_uuid' => 'forged',
        'image_uuid'        => 'forged',
    ]));

    expect(array_keys($input))->toBe(['name']);
});

test('api fleet controller creates a root fleet when no parent is supplied', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiFleetControllerProbe();
    $controller->create(fleetopsCreateFleetRequest(['name' => 'Root Fleet']));

    expect($controller->createdFleets[0])->not->toHaveKey('parent_fleet_uuid');
});

test('api fleet controller clears the parent fleet when it is sent null', function () {
    session(['company' => 'company-uuid']);

    $controller        = new FleetOpsApiFleetControllerProbe();
    $controller->fleet = fleetopsFleetFake([
        'uuid'              => 'child-uuid',
        'public_id'         => 'fleet_child',
        'parent_fleet_uuid' => 'parent-uuid',
    ]);

    $controller->update('fleet_child', fleetopsUpdateFleetRequest(['parent_fleet' => null]));

    expect($controller->fleet->updates[0])->toBe(['parent_fleet_uuid' => null])
        ->and($controller->relationLookups)->toBe([]);
});

test('api fleet controller rejects a fleet that names itself as its parent', function () {
    session(['company' => 'company-uuid']);

    $controller        = new FleetOpsApiFleetControllerProbe();
    $controller->fleet = fleetopsFleetFake(['uuid' => 'fleet_self-uuid', 'public_id' => 'fleet_self']);

    $response = $controller->update('fleet_self', fleetopsUpdateFleetRequest(['parent_fleet' => 'fleet_self']));

    expect($response)->toBe([
        'json'   => ['error' => 'A fleet cannot be its own parent fleet.'],
        'status' => 422,
    ])->and($controller->fleet->updates)->toBe([]);
});

test('api fleet controller rejects a parent that sits below the fleet in the tree', function () {
    session(['company' => 'company-uuid']);

    $controller        = new FleetOpsApiFleetControllerProbe();
    $controller->fleet = fleetopsFleetFake(['uuid' => 'root-uuid', 'public_id' => 'fleet_root']);

    // grandchild -> child -> root: making the grandchild the root's parent
    // would close the loop.
    $controller->parentChain = [
        'fleet_grandchild-uuid' => 'fleet_child-uuid',
        'fleet_child-uuid'      => 'root-uuid',
    ];

    $response = $controller->update('fleet_root', fleetopsUpdateFleetRequest([
        'parent_fleet' => 'fleet_grandchild',
    ]));

    expect($response)->toBe([
        'json'   => ['error' => 'A fleet cannot be assigned beneath one of its own subfleets.'],
        'status' => 422,
    ])->and($controller->fleet->updates)->toBe([]);
});

test('api fleet controller accepts a parent that is not an ancestor of the fleet', function () {
    session(['company' => 'company-uuid']);

    $controller        = new FleetOpsApiFleetControllerProbe();
    $controller->fleet = fleetopsFleetFake(['uuid' => 'fleet-uuid', 'public_id' => 'fleet_child']);

    $controller->parentChain = ['fleet_parent-uuid' => 'unrelated-root-uuid'];

    $controller->update('fleet_child', fleetopsUpdateFleetRequest(['parent_fleet' => 'fleet_parent']));

    expect($controller->fleet->updates[0])->toBe(['parent_fleet_uuid' => 'fleet_parent-uuid']);
});

test('api fleet controller rejects relationships that belong to another company', function () {
    session(['company' => 'company-uuid']);

    foreach (['parent_fleet', 'vendor', 'zone', 'service_area'] as $relation) {
        $controller               = new FleetOpsApiFleetControllerProbe();
        $controller->unresolvable = ['other_company_id'];

        $created = $controller->create(fleetopsCreateFleetRequest([
            'name'    => 'Carpool',
            $relation => 'other_company_id',
        ]));

        // A cross-company identifier is answered exactly as a missing one, so
        // the response cannot be used to discover another organization's data.
        expect($created)->toBe([
            'json'   => ['error' => 'No ' . str_replace('_', ' ', $relation) . ' resource found for the identifier provided.'],
            'status' => 404,
        ]);
    }
});

test('api fleet controller updates queries finds and deletes fleets', function () {
    session(['company' => 'company-uuid']);

    $fleet = fleetopsFleetFake(['uuid' => 'fleet-uuid', 'name' => 'Old Fleet']);

    $controller               = new FleetOpsApiFleetControllerProbe();
    $controller->fleet        = $fleet;
    $controller->queryResults = [['uuid' => 'fleet-a'], ['uuid' => 'fleet-b']];

    $updated = $controller->update('fleet-public', fleetopsUpdateFleetRequest([
        'name'         => 'Updated Fleet',
        'service_area' => 'service-area-public',
    ]));
    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('fleet-public', new Request());
    $deleted = $controller->delete('fleet-public', new Request());

    expect($updated)->toBe(['resource' => 'fleet', 'fleet' => $fleet])
        ->and($fleet->updates[0])->toBe([
            'name'              => 'Updated Fleet',
            'service_area_uuid' => 'service-area-public-uuid',
        ])
        ->and($query)->toBe([
            'collection' => 'fleet',
            'items'      => [['uuid' => 'fleet-a'], ['uuid' => 'fleet-b']],
        ])
        ->and($found)->toBe(['resource' => 'fleet', 'fleet' => $fleet])
        ->and($deleted)->toBe(['resource' => 'deleted-fleet', 'fleet' => $fleet])
        ->and($fleet->lookup_id)->toBe('fleet-public')
        ->and($fleet->deletedForTest)->toBeTrue();
});

test('api fleet controller returns missing fleet responses for update find and delete', function () {
    $controller                = new FleetOpsApiFleetControllerProbe();
    $controller->fleetNotFound = true;

    $expected = [
        'json'   => ['error' => 'Fleet resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-fleet', fleetopsUpdateFleetRequest(['name' => 'Missing'])))->toBe($expected)
        ->and($controller->find('missing-fleet', new Request()))->toBe($expected)
        ->and($controller->delete('missing-fleet', new Request()))->toBe($expected);
});

test('api fleet controller answers membership changes with a stable public id shape', function () {
    session(['company' => 'company-uuid']);

    $vehicle = new FleetOpsApiFleetVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'public_id' => 'vehicle_123']);

    $driver = new FleetOpsApiFleetDriverFake();
    $driver->setRawAttributes(['uuid' => 'driver-uuid', 'public_id' => 'driver_123']);

    $controller          = new FleetOpsApiFleetControllerProbe();
    $controller->fleet   = fleetopsFleetFake(['uuid' => 'fleet-uuid', 'public_id' => 'fleet_123']);
    $controller->vehicle = $vehicle;
    $controller->driver  = $driver;

    // All four operations answer in the same shape, and never with a uuid.
    expect($controller->assignVehicle('fleet_123', 'vehicle_123'))->toBe([
        'json'   => ['fleet' => 'fleet_123', 'vehicle' => 'vehicle_123', 'assigned' => true],
        'status' => 200,
    ])->and($controller->removeVehicle('fleet_123', 'vehicle_123'))->toBe([
        'json'   => ['fleet' => 'fleet_123', 'vehicle' => 'vehicle_123', 'assigned' => false],
        'status' => 200,
    ])->and($controller->assignDriver('fleet_123', 'driver_123'))->toBe([
        'json'   => ['fleet' => 'fleet_123', 'driver' => 'driver_123', 'assigned' => true],
        'status' => 200,
    ])->and($controller->removeDriver('fleet_123', 'driver_123'))->toBe([
        'json'   => ['fleet' => 'fleet_123', 'driver' => 'driver_123', 'assigned' => false],
        'status' => 200,
    ])->and($controller->membershipCalls)->toBe([
        ['assign-vehicle', 'fleet_123', 'vehicle_123'],
        ['remove-vehicle', 'fleet_123', 'vehicle_123'],
        ['assign-driver', 'fleet_123', 'driver_123'],
        ['remove-driver', 'fleet_123', 'driver_123'],
    ]);
});

test('api fleet controller treats an unavailable fleet or resource as not found for membership', function () {
    session(['company' => 'company-uuid']);

    $missingFleet                = new FleetOpsApiFleetControllerProbe();
    $missingFleet->fleetNotFound = true;

    $missingResource                   = new FleetOpsApiFleetControllerProbe();
    $missingResource->fleet            = fleetopsFleetFake(['uuid' => 'fleet-uuid', 'public_id' => 'fleet_123']);
    $missingResource->resourceNotFound = true;

    // A resource in another company is unavailable, not forbidden — the answer
    // is the same one a caller gets for an id that does not exist at all.
    expect($missingFleet->assignVehicle('fleet_other', 'vehicle_123'))->toBe([
        'json'   => ['error' => 'Fleet or vehicle resource not found.'],
        'status' => 404,
    ])->and($missingFleet->removeDriver('fleet_other', 'driver_123'))->toBe([
        'json'   => ['error' => 'Fleet or driver resource not found.'],
        'status' => 404,
    ])->and($missingResource->assignVehicle('fleet_123', 'vehicle_other'))->toBe([
        'json'   => ['error' => 'Fleet or vehicle resource not found.'],
        'status' => 404,
    ])->and($missingResource->assignDriver('fleet_123', 'driver_other'))->toBe([
        'json'   => ['error' => 'Fleet or driver resource not found.'],
        'status' => 404,
    ])->and($missingFleet->membershipCalls)->toBe([])
        ->and($missingResource->membershipCalls)->toBe([]);
});
