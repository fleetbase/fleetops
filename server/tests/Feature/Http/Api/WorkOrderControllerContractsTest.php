<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\WorkOrderController;
use Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateWorkOrderRequest;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetOpsApiWorkOrderControllerProbe extends WorkOrderController
{
    public ?FleetOpsApiWorkOrderFake $createdWorkOrder = null;
    public array $created                              = [];
    public array $models                               = [];
    public array $resources                            = [];
    public array $deletedResources                     = [];
    public array $collections                          = [];
    public array $queries                              = [];
    public array $morphs                               = [];
    public array $mail                                 = [];
    public array $activity                             = [];

    protected function createWorkOrder(array $input): WorkOrder
    {
        $this->created[] = $input;
        $this->createdWorkOrder ??= fleetopsApiWorkOrderFake('created-work-order', 'work_order_created');
        $this->createdWorkOrder->forceFill($input);

        return $this->createdWorkOrder;
    }

    protected function queryWorkOrdersWithRequest(Request $request, callable $callback)
    {
        $query = new FleetOpsApiWorkOrderQueryFake();
        $callback($query);
        $this->queries[] = $query->calls;

        return [
            fleetopsApiWorkOrderFake('work-order-a', 'work_order_a'),
            fleetopsApiWorkOrderFake('work-order-b', 'work_order_b'),
        ];
    }

    protected function resolveModel(string $modelClass, string $id, ?string $companyUuid = null): Model
    {
        $key = $modelClass . ':' . $id;

        if (!array_key_exists($key, $this->models)) {
            throw (new ModelNotFoundException())->setModel($modelClass, $id);
        }

        return $this->models[$key];
    }

    protected function resolveMorph(?string $type, ?string $id): array
    {
        $this->morphs[] = [$type, $id];

        if ($type === 'vehicle' && $id === 'vehicle_public') {
            return [Vehicle::class, 'vehicle-uuid'];
        }

        if ($type === 'vendor' && $id === 'vendor_public') {
            return ['Fleetbase\\FleetOps\\Models\\Vendor', 'vendor-uuid'];
        }

        return parent::resolveMorph($type, $id);
    }

    protected function workOrderResource(WorkOrder $workOrder)
    {
        $this->resources[] = $workOrder->uuid;

        return [
            'uuid'      => $workOrder->uuid,
            'public_id' => $workOrder->public_id,
            'status'    => $workOrder->status,
        ];
    }

    protected function workOrderResourceCollection($workOrders)
    {
        $items               = collect($workOrders)->values()->all();
        $this->collections[] = $items;

        return ['collection' => $items];
    }

    protected function deletedWorkOrderResource(WorkOrder $workOrder)
    {
        $this->deletedResources[] = $workOrder->uuid;

        return ['deleted' => $workOrder->uuid];
    }

    protected function sendWorkOrderDispatchedMail(string $email, WorkOrder $workOrder): void
    {
        $this->mail[] = [$email, $workOrder->public_id];
    }

    protected function recordWorkOrderSentActivity(WorkOrder $workOrder, string $email): void
    {
        $this->activity[] = [$workOrder->public_id, $email];
    }
}

class FleetOpsApiWorkOrderFake extends WorkOrder
{
    public array $loads    = [];
    public array $updates  = [];
    public bool $deleted   = false;
    public bool $refreshed = false;

    public function load($relations)
    {
        $this->loads[] = $relations;

        return $this;
    }

    public function refresh()
    {
        $this->refreshed = true;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsApiWorkOrderQueryFake
{
    public array $calls = [];

    public function with($relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }
}

function fleetopsApiWorkOrderController(): FleetOpsApiWorkOrderControllerProbe
{
    return new FleetOpsApiWorkOrderControllerProbe();
}

function fleetopsApiWorkOrderFake(string $uuid = 'work-order-uuid', string $publicId = 'work_order_public', ?object $assignee = null): FleetOpsApiWorkOrderFake
{
    $workOrder = new FleetOpsApiWorkOrderFake();
    $workOrder->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
        'status'    => 'open',
        'subject'   => 'Inspect vehicle',
    ], true);
    $workOrder->setRelation('assignee', $assignee);
    $workOrder->setAppends([]);

    return $workOrder;
}

function fleetopsApiWorkOrderRequest(string $class, array $input): Request
{
    return $class::create('/api/work-orders', 'POST', $input);
}

test('api work order controller creates work orders with resolved morph relations', function () {
    session(['company' => 'company-uuid']);

    $controller = fleetopsApiWorkOrderController();
    $response   = $controller->create(fleetopsApiWorkOrderRequest(CreateWorkOrderRequest::class, [
        'subject'       => 'Repair left tire',
        'category'      => 'repair',
        'status'        => 'open',
        'priority'      => 'high',
        'target_type'   => 'vehicle',
        'target'        => 'vehicle_public',
        'assignee_type' => 'vendor',
        'assignee'      => 'vendor_public',
        'instructions'  => 'Replace tire and inspect brakes.',
    ]));

    expect($response)->toMatchArray([
        'uuid'      => 'created-work-order',
        'public_id' => 'work_order_created',
        'status'    => 'open',
    ])
        ->and($controller->created)->toHaveCount(1)
        ->and($controller->created[0])->toMatchArray([
            'company_uuid'   => 'company-uuid',
            'subject'        => 'Repair left tire',
            'category'       => 'repair',
            'priority'       => 'high',
            'target_type'    => Vehicle::class,
            'target_uuid'    => 'vehicle-uuid',
            'assignee_uuid'  => 'vendor-uuid',
            'instructions'   => 'Replace tire and inspect brakes.',
        ])
        ->and($controller->morphs)->toBe([
            ['vehicle', 'vehicle_public'],
            ['vendor', 'vendor_public'],
        ])
        ->and($controller->createdWorkOrder->loads)->toBe([['target', 'assignee']]);
});

test('api work order controller clears blank morph relations and rejects uuid identifiers', function () {
    $controller = fleetopsApiWorkOrderController();
    $response   = $controller->create(fleetopsApiWorkOrderRequest(CreateWorkOrderRequest::class, [
        'subject'  => 'Inspect equipment',
        'target'   => '',
        'assignee' => null,
    ]));

    expect($response['uuid'])->toBe('created-work-order')
        ->and($controller->created[0])->toMatchArray([
            'target_type'   => null,
            'target_uuid'   => null,
            'assignee_type' => null,
            'assignee_uuid' => null,
        ]);

    expect(fn () => $controller->create(fleetopsApiWorkOrderRequest(CreateWorkOrderRequest::class, [
        'target_uuid' => 'a27b6f9b-8d1a-4dbd-9a63-a08497fa6628',
    ])))->toThrow(ValidationException::class);
});

test('api work order controller updates finds deletes and queries work orders', function () {
    $controller                                                  = fleetopsApiWorkOrderController();
    $workOrder                                                   = fleetopsApiWorkOrderFake();
    $controller->models[WorkOrder::class . ':work_order_public'] = $workOrder;

    $updated = $controller->update('work_order_public', fleetopsApiWorkOrderRequest(UpdateWorkOrderRequest::class, [
        'subject'  => 'Updated subject',
        'priority' => 'urgent',
        'target'   => '',
    ]));

    expect($updated)->toMatchArray([
        'uuid'      => 'work-order-uuid',
        'public_id' => 'work_order_public',
    ])
        ->and($workOrder->updates)->toBe([[
            'subject'     => 'Updated subject',
            'priority'    => 'urgent',
            'target_type' => null,
            'target_uuid' => null,
        ]])
        ->and($workOrder->refreshed)->toBeTrue()
        ->and($workOrder->loads)->toContain(['target', 'assignee']);

    expect($controller->find('work_order_public'))->toMatchArray([
        'uuid'      => 'work-order-uuid',
        'public_id' => 'work_order_public',
    ])
        ->and($controller->delete('work_order_public'))->toBe(['deleted' => 'work-order-uuid'])
        ->and($workOrder->deleted)->toBeTrue();

    $query = $controller->query(Request::create('/api/work-orders', 'GET', ['status' => 'open']));

    expect($query['collection'])->toHaveCount(2)
        ->and($controller->queries)->toBe([
            [['with', ['target', 'assignee']]],
        ]);
});

test('api work order controller reports missing resources', function () {
    $controller = fleetopsApiWorkOrderController();

    expect($controller->find('missing-work-order')->getStatusCode())->toBe(404)
        ->and($controller->update('missing-work-order', fleetopsApiWorkOrderRequest(UpdateWorkOrderRequest::class, []))->getData(true))->toBe([
            'error' => 'WorkOrder resource not found.',
        ])
        ->and($controller->delete('missing-work-order')->getData(true))->toBe([
            'error' => 'WorkOrder resource not found.',
        ])
        ->and($controller->send('missing-work-order')->getData(true))->toBe([
            'error' => 'WorkOrder resource not found.',
        ]);
});

test('api work order controller sends work order emails and validates recipients', function () {
    $controller = fleetopsApiWorkOrderController();

    $controller->models[WorkOrder::class . ':missing-assignee'] = fleetopsApiWorkOrderFake('wo-1', 'missing_assignee', null);
    $missingAssignee                                            = $controller->send('missing-assignee');

    expect($missingAssignee->getStatusCode())->toBe(422)
        ->and($missingAssignee->getData(true))->toBe([
            'error' => 'This work order has no assigned vendor.',
        ]);

    $controller->models[WorkOrder::class . ':missing-email'] = fleetopsApiWorkOrderFake('wo-2', 'missing_email', (object) ['email' => null]);
    $missingEmail                                            = $controller->send('missing-email');

    expect($missingEmail->getStatusCode())->toBe(422)
        ->and($missingEmail->getData(true))->toBe([
            'error' => 'The assigned vendor has no email address on file.',
        ]);

    $controller->models[WorkOrder::class . ':sendable'] = fleetopsApiWorkOrderFake('wo-3', 'sendable', (object) ['email' => 'vendor@example.test']);
    $sent                                               = $controller->send('sendable');

    expect($sent->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'Work order successfully sent to vendor@example.test',
    ])
        ->and($controller->mail)->toBe([['vendor@example.test', 'sendable']])
        ->and($controller->activity)->toBe([['sendable', 'vendor@example.test']]);
});
