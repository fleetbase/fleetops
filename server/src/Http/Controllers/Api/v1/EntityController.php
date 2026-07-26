<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateEntityRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateEntityRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Entity as EntityResource;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EntityController extends Controller
{
    /**
     * Creates a new Fleetbase Entity resource.
     *
     * @param \Fleetbase\Http\Requests\CreateEntityRequest $request
     *
     * @return \Fleetbase\Http\Resources\Entity
     */
    public function create(CreateEntityRequest $request)
    {
        // get request input
        $input = $this->entityInputFromRequest($request);

        // payload assignment
        if ($request->has('payload')) {
            $input['payload_uuid'] = $this->getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => session('company'),
            ]);
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = $this->getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('customer'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($customer)) {
                $input['customer_uuid'] = $this->getValue($customer, 'uuid');
                $input['customer_type'] = $this->getModelClassName($this->getValue($customer, 'table'));
            }
        }

        // driver assignment
        if ($request->has('driver')) {
            $input['driver_uuid'] = $this->getUuid('drivers', [
                'public_id'    => $request->input('driver'),
                'company_uuid' => session('company'),
            ]);
        }

        // if destination is set
        if ($request->has('destination') || $request->has('waypoint')) {
            $destinationKey = $request->or(['destination', 'waypoint']);

            if ($request->has('payload')) {
                $payload = $this->findPayloadByPublicId($request->input('payload'));

                if ($payload) {
                    // if a destination or waypoint is explicitly set
                    $destination = $payload->findDestinationFromKey($destinationKey);
                    if ($destination instanceof Place) {
                        $input['destination_uuid'] = $destination->uuid;
                    }
                }
            }
        }

        // make sure company is set
        $input['company_uuid'] = session('company');

        // create the entity
        $entity = $this->createEntity($input);

        // response the driver resource
        return $this->entityResource($entity);
    }

    /**
     * Updates a Fleetbase Entity resource.
     *
     * @param string                                       $id
     * @param \Fleetbase\Http\Requests\UpdateEntityRequest $request
     *
     * @return \Fleetbase\Http\Resources\Entity
     */
    public function update($id, UpdateEntityRequest $request)
    {
        // find for the entity
        try {
            $entity = $this->findEntityOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Entity resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $this->entityInputFromRequest($request);

        // payload assignment
        if ($request->has('payload')) {
            $input['payload_uuid'] = $this->getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => session('company'),
            ]);
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = $this->getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('customer'),
                    'company_uuid' => session('company'),
                ]
            );
            if (is_array($customer)) {
                $input['customer_uuid']   = $this->getValue($customer, 'uuid');
                $input['customer_object'] = $this->singularize($this->getValue($customer, 'table'));
            }
        }

        // driver assignment
        if ($request->has('driver')) {
            $input['driver_uuid'] = $this->getUuid('drivers', [
                'public_id'    => $request->input('driver'),
                'company_uuid' => session('company'),
            ]);
        }

        // if destination is set
        if ($request->has('destination') || $request->has('waypoint')) {
            $destinationKey = $request->or(['destination', 'waypoint']);

            if ($request->has('payload')) {
                $payload = $this->findPayloadByPublicId($request->input('payload'));

                if ($payload) {
                    // if a destination or waypoint is explicitly set
                    $destination = $payload->findDestinationFromKey($destinationKey);
                    if ($destination instanceof Place) {
                        $input['destination_uuid'] = $destination->uuid;
                    }
                }
            }
        }

        // update the entity
        $entity->update($input);

        // response the entity resource
        return $this->entityResource($entity);
    }

    /**
     * Query for Fleetbase Entity resources.
     *
     * @return \Fleetbase\Http\Resources\EntityCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryEntities($request);

        return $this->entityResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Entity resources.
     *
     * @return \Fleetbase\Http\Resources\EntityCollection
     */
    public function find($id, Request $request)
    {
        // find for the entity
        try {
            $entity = $this->findEntityOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Entity resource not found.',
                ],
                404
            );
        }

        // response the entity resource
        return $this->entityResource($entity);
    }

    /**
     * Deletes a Fleetbase Entity resources.
     *
     * @return \Fleetbase\Http\Resources\EntityCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $entity = $this->findEntityOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Entity resource not found.',
                ],
                404
            );
        }

        // delete the entity
        $entity->delete();

        // response the entity resource
        return $this->deletedEntityResource($entity);
    }

    protected function entityInputFromRequest(Request $request): array
    {
        return $request->only([
            'name',
            'type',
            'internal_id',
            'description',
            'meta',
            'length',
            'width',
            'height',
            'weight',
            'weight_unit',
            'dimensions_unit',
            'declared_value',
            'price',
            'sales_price',
            'sku',
            'currency',
            'meta',
            'supplier_uuid',
        ]);
    }

    protected function getUuid(array|string $table, array $where): mixed
    {
        return Utils::getUuid($table, $where);
    }

    protected function getValue(array|object $data, string $key): mixed
    {
        return Utils::get($data, $key);
    }

    protected function getModelClassName(?string $table): ?string
    {
        return $table ? Utils::getModelClassName($table) : null;
    }

    protected function singularize(?string $value): ?string
    {
        return $value ? Utils::singularize($value) : null;
    }

    protected function findPayloadByPublicId(string $publicId): ?Payload
    {
        return Payload::where('public_id', $publicId)->first();
    }

    protected function createEntity(array $input): Entity
    {
        return Entity::create($input);
    }

    protected function findEntityOrFail(string $id): Entity
    {
        return Entity::findRecordOrFail($id);
    }

    protected function queryEntities(Request $request): mixed
    {
        return Entity::queryWithRequest($request);
    }

    protected function entityResource(Entity $entity)
    {
        return new EntityResource($entity);
    }

    protected function entityResourceCollection(mixed $results): mixed
    {
        return EntityResource::collection($results);
    }

    protected function deletedEntityResource(Entity $entity)
    {
        return new DeletedResource($entity);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
