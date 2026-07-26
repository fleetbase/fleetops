<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PayloadController;
use Fleetbase\FleetOps\Http\Requests\CreatePayloadRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePayloadRequest;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiPayloadControllerProbe extends PayloadController
{
    public ?FleetOpsApiPayloadEndpointFake $payload = null;
    public array $createdPayloads                   = [];
    public mixed $queryResults                      = null;
    public bool $payloadNotFound                    = false;

    protected function newPayload(array $input): Payload
    {
        $payload = new FleetOpsApiPayloadEndpointFake();
        $payload->setRawAttributes($input, true);
        $payload->firstWaypoint  = fleetopsApiPayloadPlace();
        $this->createdPayloads[] = $payload;

        return $payload;
    }

    protected function findPayloadOrFail(string $id, array $with = []): Payload
    {
        if ($this->payloadNotFound) {
            throw new ModelNotFoundException();
        }

        $this->payload ??= new FleetOpsApiPayloadEndpointFake();
        $this->payload->lookupId   = $id;
        $this->payload->lookupWith = $with;

        return $this->payload;
    }

    protected function queryPayloads(Request $request): mixed
    {
        return $this->queryResults ?? [['uuid' => 'payload-1']];
    }

    protected function payloadResource(Payload $payload): array
    {
        return ['resource' => 'payload', 'payload' => $payload];
    }

    protected function payloadResourceCollection(mixed $results): array
    {
        return ['collection' => 'payload', 'items' => $results];
    }

    protected function deletedPayloadResource(Payload $payload): array
    {
        return ['resource' => 'deleted-payload', 'payload' => $payload];
    }

    protected function jsonResponse(array $payload, int $status): array
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiPayloadEndpointFake extends Payload
{
    public array $pickupAssignments          = [];
    public array $dropoffAssignments         = [];
    public array $returnAssignments          = [];
    public array $waypointAssignments        = [];
    public array $entityAssignments          = [];
    public array $currentWaypointAssignments = [];
    public array $loadedRelations            = [];
    public array $filledPayloads             = [];
    public bool $removedWaypoints            = false;
    public bool $savedForTest                = false;
    public bool $deletedForTest              = false;
    public ?Place $firstWaypoint             = null;
    public ?string $lookupId                 = null;
    public array $lookupWith                 = [];

    public function setPickup($place, array $options = [])
    {
        $this->pickupAssignments[] = $place;

        return $this;
    }

    public function setDropoff($place, array $options = [])
    {
        $this->dropoffAssignments[] = $place;

        return $this;
    }

    public function setReturn($place, array $options = [])
    {
        $this->returnAssignments[] = $place;

        return $this;
    }

    public function setWaypoints($waypoints = [])
    {
        $this->waypointAssignments[] = $waypoints;

        return $this;
    }

    public function removeWaypoints()
    {
        $this->removedWaypoints = true;

        return $this;
    }

    public function setEntities($entities = [])
    {
        $this->entityAssignments[] = $entities;

        return $this;
    }

    public function getPickupOrFirstWaypoint(): ?Place
    {
        return $this->firstWaypoint;
    }

    public function setCurrentWaypoint(Place|Waypoint $destination, bool $save = true): Payload
    {
        $this->currentWaypointAssignments[] = $destination->uuid;

        return $this;
    }

    public function fill(array $attributes)
    {
        $this->filledPayloads[] = $attributes;

        return parent::fill($attributes);
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiPayloadEndpointPlaceFake extends Place
{
}

function fleetopsCreatePayloadRequest(array $input): CreatePayloadRequest
{
    return CreatePayloadRequest::create('/api/v1/payloads', 'POST', $input);
}

function fleetopsUpdatePayloadRequest(array $input): UpdatePayloadRequest
{
    return new FleetOpsUpdatePayloadEndpointRequestFake($input);
}

class FleetOpsUpdatePayloadEndpointRequestFake extends UpdatePayloadRequest
{
    public function __construct(private array $payload)
    {
        parent::__construct();
    }

    public function all($keys = null): array
    {
        return $this->payload;
    }
}

function fleetopsApiPayloadPlace(): FleetOpsApiPayloadEndpointPlaceFake
{
    $place = new FleetOpsApiPayloadEndpointPlaceFake();
    $place->setRawAttributes(['uuid' => 'place-uuid'], true);

    return $place;
}

function fleetopsApiPayloadController(): FleetOpsApiPayloadControllerProbe
{
    $controller                         = new FleetOpsApiPayloadControllerProbe();
    $controller->payload                = new FleetOpsApiPayloadEndpointFake();
    $controller->queryResults           = [['uuid' => 'payload-1'], ['uuid' => 'payload-2']];
    $controller->payload->firstWaypoint = fleetopsApiPayloadPlace();

    return $controller;
}

test('api payload controller creates payloads with route shape assignments', function () {
    session(['company' => 'company-uuid']);
    $controller = fleetopsApiPayloadController();
    $request    = fleetopsCreatePayloadRequest([
        'type'               => 'parcel',
        'provider'           => 'native',
        'meta'               => ['source' => 'api'],
        'cod_amount'         => 120,
        'cod_currency'       => 'SGD',
        'cod_payment_method' => 'cash',
        'pickup'             => ['public_id' => 'pickup-public'],
        'dropoff'            => ['public_id' => 'dropoff-public'],
        'return'             => ['public_id' => 'return-public'],
        'waypoints'          => [['public_id' => 'waypoint-public']],
        'entities'           => [['name' => 'Box']],
        'ignored'            => 'nope',
    ]);

    $response = $controller->create($request);
    $payload  = $response['payload'];

    expect($response['resource'])->toBe('payload')
        ->and($payload->getAttributes())->toMatchArray([
            'type'               => 'parcel',
            'provider'           => 'native',
            'meta'               => ['source' => 'api'],
            'cod_amount'         => 120,
            'cod_currency'       => 'SGD',
            'cod_payment_method' => 'cash',
        ])
        ->and($payload->pickupAssignments)->toBe([['public_id' => 'pickup-public']])
        ->and($payload->dropoffAssignments)->toBe([['public_id' => 'dropoff-public']])
        ->and($payload->returnAssignments)->toBe([['public_id' => 'return-public']])
        ->and($payload->waypointAssignments)->toBe([[['public_id' => 'waypoint-public']]])
        ->and($payload->entityAssignments)->toBe([[['name' => 'Box']]])
        ->and($payload->currentWaypointAssignments)->toBe(['place-uuid'])
        ->and($payload->savedForTest)->toBeTrue();
});

test('api payload controller updates route shape and removes waypoints when endpoints are explicit', function () {
    $controller = fleetopsApiPayloadController();
    $request    = fleetopsUpdatePayloadRequest([
        'type'     => 'freight',
        'provider' => 'native',
        'pickup'   => ['public_id' => 'pickup-public'],
        'entities' => [['name' => 'Pallet']],
    ]);

    $response = $controller->update('payload-public', $request);
    $payload  = $response['payload'];

    expect($payload->lookupId)->toBe('payload-public')
        ->and($payload->lookupWith)->toBe(['waypoints'])
        ->and($payload->pickupAssignments)->toBe([['public_id' => 'pickup-public']])
        ->and($payload->removedWaypoints)->toBeTrue()
        ->and($payload->entityAssignments)->toBe([[['name' => 'Pallet']]])
        ->and($payload->currentWaypointAssignments)->toBe(['place-uuid'])
        ->and($payload->loadedRelations)->toBe([['entities', 'waypoints', 'pickup', 'dropoff', 'return']]);
});

test('api payload controller keeps explicit waypoint updates and skips empty entity updates', function () {
    $controller = fleetopsApiPayloadController();
    $request    = fleetopsUpdatePayloadRequest([
        'waypoints' => [['public_id' => 'waypoint-public']],
        'entities'  => [],
    ]);

    $response = $controller->update('payload-public', $request);
    $payload  = $response['payload'];

    expect($payload->waypointAssignments)->toBe([[['public_id' => 'waypoint-public']]])
        ->and($payload->removedWaypoints)->toBeFalse()
        ->and($payload->entityAssignments)->toBe([])
        ->and($payload->savedForTest)->toBeTrue();
});

test('api payload controller queries finds and deletes payload resources', function () {
    $controller = fleetopsApiPayloadController();

    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('payload-public', new Request());
    $deleted = $controller->delete('payload-public', new Request());

    expect($query)->toBe(['collection' => 'payload', 'items' => [['uuid' => 'payload-1'], ['uuid' => 'payload-2']]])
        ->and($found)->toBe(['resource' => 'payload', 'payload' => $controller->payload])
        ->and($deleted)->toBe(['resource' => 'deleted-payload', 'payload' => $controller->payload])
        ->and($controller->payload->deletedForTest)->toBeTrue();
});

test('api payload controller returns not found responses for missing payloads', function (string $method) {
    $controller                  = fleetopsApiPayloadController();
    $controller->payloadNotFound = true;

    $response = $method === 'update'
        ? $controller->update('missing-payload', fleetopsUpdatePayloadRequest([]))
        : $controller->{$method}('missing-payload', new Request());

    expect($response)->toBe([
        'json'   => ['error' => 'Payload resource not found.'],
        'status' => 404,
    ]);
})->with(['update', 'find', 'delete']);
