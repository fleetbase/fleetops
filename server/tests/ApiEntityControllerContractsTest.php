<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\EntityController;
use Fleetbase\FleetOps\Http\Requests\CreateEntityRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateEntityRequest;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiEntityControllerProbe extends EntityController
{
    public ?FleetOpsApiEntityFake $entity   = null;
    public ?FleetOpsApiPayloadFake $payload = null;
    public array $uuidLookups               = [];
    public array $createdEntities           = [];
    public mixed $queryResults              = null;
    public bool $entityNotFound             = false;

    protected function getUuid(array|string $table, array $where): mixed
    {
        $this->uuidLookups[] = [$table, $where];

        if ($table === 'payloads') {
            return 'payload-uuid';
        }

        if ($table === 'drivers') {
            return 'driver-uuid';
        }

        return ['uuid' => 'customer-uuid', 'table' => 'contacts'];
    }

    protected function getModelClassName(?string $table): ?string
    {
        return $table === 'contacts' ? 'Fleetbase\\FleetOps\\Models\\Contact' : null;
    }

    protected function singularize(?string $value): ?string
    {
        return $value === 'contacts' ? 'contact' : null;
    }

    protected function findPayloadByPublicId(string $publicId): ?Payload
    {
        $this->payload?->setAttribute('lookup_id', $publicId);

        return $this->payload;
    }

    protected function createEntity(array $input): Entity
    {
        $this->createdEntities[] = $input;

        $entity = new FleetOpsApiEntityFake();
        $entity->setRawAttributes(array_merge(['uuid' => 'created-entity-uuid'], $input), true);

        return $entity;
    }

    protected function findEntityOrFail(string $id): Entity
    {
        if ($this->entityNotFound) {
            throw new ModelNotFoundException();
        }

        $this->entity ??= new FleetOpsApiEntityFake();
        $this->entity->setAttribute('lookup_id', $id);

        return $this->entity;
    }

    protected function queryEntities(Request $request): mixed
    {
        return $this->queryResults ?? [['uuid' => 'entity-uuid']];
    }

    protected function entityResource(Entity $entity): array
    {
        return ['resource' => 'entity', 'entity' => $entity];
    }

    protected function entityResourceCollection(mixed $results): array
    {
        return ['collection' => 'entity', 'items' => $results];
    }

    protected function deletedEntityResource(Entity $entity): array
    {
        return ['resource' => 'deleted-entity', 'entity' => $entity];
    }

    protected function jsonResponse(array $payload, int $status): array
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiEntityFake extends Entity
{
    public array $updatedPayloads = [];
    public bool $deletedForTest   = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updatedPayloads[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiPayloadFake extends Payload
{
    public array $destinationLookups = [];
    public ?Place $destination       = null;

    public function findDestinationFromKey(?string $destinationKey = null): ?Place
    {
        $this->destinationLookups[] = $destinationKey;

        return $this->destination;
    }
}

class FleetOpsApiEntityPlaceFake extends Place
{
}

class FleetOpsCreateEntityRequestFake extends CreateEntityRequest
{
    public function __construct(private array $payload)
    {
        parent::__construct();
    }

    public function only($keys)
    {
        return collect($this->payload)->only(is_array($keys) ? $keys : func_get_args())->all();
    }

    public function has($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (!array_key_exists($item, $this->payload)) {
                    return false;
                }
            }

            return true;
        }

        return array_key_exists($key, $this->payload);
    }

    public function input($key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }

        return $this->payload[$key] ?? $default;
    }

    public function or(array $keys)
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return $this->input($key);
            }
        }

        return null;
    }
}

class FleetOpsUpdateEntityRequestFake extends UpdateEntityRequest
{
    public function __construct(private array $payload)
    {
        parent::__construct();
    }

    public function only($keys)
    {
        return collect($this->payload)->only(is_array($keys) ? $keys : func_get_args())->all();
    }

    public function has($key): bool
    {
        return array_key_exists($key, $this->payload);
    }

    public function input($key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }

        return $this->payload[$key] ?? $default;
    }

    public function or(array $keys)
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return $this->input($key);
            }
        }

        return null;
    }
}

function fleetopsApiEntityController(): FleetOpsApiEntityControllerProbe
{
    $controller               = new FleetOpsApiEntityControllerProbe();
    $controller->payload      = new FleetOpsApiPayloadFake();
    $controller->entity       = new FleetOpsApiEntityFake();
    $controller->queryResults = [['uuid' => 'entity-1'], ['uuid' => 'entity-2']];

    $place = new FleetOpsApiEntityPlaceFake();
    $place->setRawAttributes(['uuid' => 'destination-uuid'], true);
    $controller->payload->destination = $place;

    return $controller;
}

test('api entity controller creates entities with assignment lookups and payload destinations', function () {
    session(['company' => 'company-uuid']);
    $controller = fleetopsApiEntityController();
    $request    = new FleetOpsCreateEntityRequestFake([
        'name'        => 'Pallet',
        'type'        => 'cargo',
        'payload'     => 'payload-public',
        'customer'    => 'customer-public',
        'driver'      => 'driver-public',
        'destination' => 'dropoff',
        'sku'         => 'PALLET-1',
    ]);

    $response = $controller->create($request);

    expect($response['resource'])->toBe('entity')
        ->and($controller->createdEntities[0])->toMatchArray([
            'name'             => 'Pallet',
            'type'             => 'cargo',
            'sku'              => 'PALLET-1',
            'payload_uuid'     => 'payload-uuid',
            'customer_uuid'    => 'customer-uuid',
            'customer_type'    => 'Fleetbase\\FleetOps\\Models\\Contact',
            'driver_uuid'      => 'driver-uuid',
            'destination_uuid' => 'destination-uuid',
            'company_uuid'     => 'company-uuid',
        ])
        ->and($controller->uuidLookups)->toBe([
            ['payloads', ['public_id' => 'payload-public', 'company_uuid' => 'company-uuid']],
            [['contacts', 'vendors'], ['public_id' => 'customer-public', 'company_uuid' => 'company-uuid']],
            ['drivers', ['public_id'               => 'driver-public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($controller->payload->lookup_id)->toBe('payload-public')
        ->and($controller->payload->destinationLookups)->toBe(['dropoff']);
});

test('api entity controller updates entities with customer and waypoint assignments', function () {
    session(['company' => 'company-uuid']);
    $controller = fleetopsApiEntityController();
    $request    = new FleetOpsUpdateEntityRequestFake([
        'name'     => 'Updated pallet',
        'payload'  => 'payload-public',
        'customer' => 'customer-public',
        'driver'   => 'driver-public',
        'waypoint' => 'return',
    ]);

    $response = $controller->update('entity-public', $request);

    expect($response['entity']->lookup_id)->toBe('entity-public')
        ->and($controller->entity->updatedPayloads[0])->toMatchArray([
            'name'             => 'Updated pallet',
            'payload_uuid'     => 'payload-uuid',
            'customer_uuid'    => 'customer-uuid',
            'customer_object'  => 'contact',
            'driver_uuid'      => 'driver-uuid',
            'destination_uuid' => 'destination-uuid',
        ])
        ->and($controller->uuidLookups[1])->toBe([
            ['contacts', 'vendors'],
            ['public_id' => 'customer-public', 'company_uuid' => 'company-uuid'],
        ])
        ->and($controller->payload->destinationLookups)->toBe(['return']);
});

test('api entity controller queries finds and deletes entity resources', function () {
    $controller = fleetopsApiEntityController();

    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('entity-public', new Request());
    $deleted = $controller->delete('entity-public', new Request());

    expect($query)->toBe(['collection' => 'entity', 'items' => [['uuid' => 'entity-1'], ['uuid' => 'entity-2']]])
        ->and($found)->toBe(['resource' => 'entity', 'entity' => $controller->entity])
        ->and($deleted)->toBe(['resource' => 'deleted-entity', 'entity' => $controller->entity])
        ->and($controller->entity->deletedForTest)->toBeTrue();
});

test('api entity controller returns not found responses for missing entities', function (string $method) {
    $controller                 = fleetopsApiEntityController();
    $controller->entityNotFound = true;

    $response = $method === 'update'
        ? $controller->update('missing-entity', new FleetOpsUpdateEntityRequestFake([]))
        : $controller->{$method}('missing-entity', new Request());

    expect($response)->toBe([
        'json'   => ['error' => 'Entity resource not found.'],
        'status' => 404,
    ]);
})->with(['update', 'find', 'delete']);
