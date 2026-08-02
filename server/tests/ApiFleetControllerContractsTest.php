<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Fleetbase\FleetOps\Http\Requests\CreateFleetRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFleetRequest;
use Fleetbase\FleetOps\Models\Fleet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiFleetControllerProbe extends FleetController
{
    public ?Fleet $fleet             = null;
    public array $createdFleets      = [];
    public array $serviceAreaLookups = [];
    public mixed $queryResults       = null;
    public bool $fleetNotFound       = false;

    protected function getServiceAreaUuid(string $table, array $where): ?string
    {
        $this->serviceAreaLookups[] = [$table, $where];

        return 'service-area-uuid';
    }

    protected function createFleet(array $input): Fleet
    {
        $this->createdFleets[] = $input;

        $fleet = new FleetOpsApiFleetFake();
        $fleet->setRawAttributes(array_merge(['uuid' => 'created-fleet-uuid'], $input));

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

function fleetopsCreateFleetRequest(array $input): CreateFleetRequest
{
    return CreateFleetRequest::create('/api/v1/fleets', 'POST', $input);
}

function fleetopsUpdateFleetRequest(array $input): UpdateFleetRequest
{
    return UpdateFleetRequest::create('/api/v1/fleets/fleet-public', 'PUT', $input);
}

test('api fleet controller creates fleets with service area resolution', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiFleetControllerProbe();

    $response = $controller->create(fleetopsCreateFleetRequest([
        'name'         => 'Downtown Fleet',
        'service_area' => 'service-area-public',
        'ignored'      => 'not copied',
    ]));

    expect($response['resource'])->toBe('fleet')
        ->and($controller->serviceAreaLookups)->toBe([
            [
                'service_areas',
                [
                    'public_id'    => 'service-area-public',
                    'company_uuid' => 'company-uuid',
                ],
            ],
        ])
        ->and($controller->createdFleets[0])->toBe([
            'name'              => 'Downtown Fleet',
            'company_uuid'      => 'company-uuid',
            'service_area_uuid' => 'service-area-uuid',
        ]);
});

test('api fleet controller updates queries finds and deletes fleets', function () {
    session(['company' => 'company-uuid']);

    $fleet = new FleetOpsApiFleetFake();
    $fleet->setRawAttributes(['uuid' => 'fleet-uuid', 'name' => 'Old Fleet']);

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
            'service_area_uuid' => 'service-area-uuid',
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
