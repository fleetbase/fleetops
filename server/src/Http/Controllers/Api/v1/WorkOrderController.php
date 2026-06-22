<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateWorkOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateWorkOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\WorkOrder as WorkOrderResource;
use Fleetbase\FleetOps\Mail\WorkOrderDispatched;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class WorkOrderController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function create(CreateWorkOrderRequest $request)
    {
        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        $workOrder = WorkOrder::create($input)->load(['target', 'assignee']);

        return new WorkOrderResource($workOrder);
    }

    public function update(string $id, UpdateWorkOrderRequest $request)
    {
        try {
            $workOrder = $this->resolveModel(WorkOrder::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'WorkOrder resource not found.'], 404);
        }

        $workOrder->update($this->input($request));

        return new WorkOrderResource($workOrder->refresh()->load(['target', 'assignee']));
    }

    public function query(Request $request)
    {
        $results = WorkOrder::queryWithRequest($request, function (&$query) {
            $query->with(['target', 'assignee']);
        });

        return WorkOrderResource::collection($results);
    }

    public function find(string $id)
    {
        try {
            $workOrder = $this->resolveModel(WorkOrder::class, $id)->load(['target', 'assignee']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'WorkOrder resource not found.'], 404);
        }

        return new WorkOrderResource($workOrder);
    }

    public function delete(string $id)
    {
        try {
            $workOrder = $this->resolveModel(WorkOrder::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'WorkOrder resource not found.'], 404);
        }

        $workOrder->delete();

        return new DeletedResource($workOrder);
    }

    public function send(string $id): JsonResponse
    {
        try {
            $workOrder = $this->resolveModel(WorkOrder::class, $id)->load(['target', 'assignee']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'WorkOrder resource not found.'], 404);
        }

        $assignee = $workOrder->assignee;
        if (!$assignee) {
            return response()->json(['error' => 'This work order has no assigned vendor.'], 422);
        }

        $email = $assignee->email ?? null;
        if (!$email) {
            return response()->json(['error' => 'The assigned vendor has no email address on file.'], 422);
        }

        Mail::to($email)->send(new WorkOrderDispatched($workOrder));

        activity('work_order_sent')
            ->performedOn($workOrder)
            ->withProperties(['sent_to' => $email])
            ->log('Work order emailed to vendor');

        return response()->json([
            'status'  => 'ok',
            'message' => 'Work order successfully sent to ' . $email,
        ]);
    }

    protected function input(Request $request): array
    {
        $input = $request->only([
            'code',
            'subject',
            'category',
            'status',
            'priority',
            'opened_at',
            'due_at',
            'closed_at',
            'instructions',
            'checklist',
            'estimated_cost',
            'approved_budget',
            'actual_cost',
            'currency',
            'cost_breakdown',
            'cost_center',
            'budget_code',
            'meta',
        ]);

        if ($request->exists('target')) {
            if (blank($request->input('target'))) {
                $input['target_type'] = null;
                $input['target_uuid'] = null;
            } else {
                [$type, $uuid]        = $this->resolveMorph($request->input('target_type'), $request->input('target'));
                $input['target_type'] = $type;
                $input['target_uuid'] = $uuid;
            }
        }

        if ($request->exists('assignee')) {
            if (blank($request->input('assignee'))) {
                $input['assignee_type'] = null;
                $input['assignee_uuid'] = null;
            } else {
                [$type, $uuid]          = $this->resolveMorph($request->input('assignee_type'), $request->input('assignee'));
                $input['assignee_type'] = $type;
                $input['assignee_uuid'] = $uuid;
            }
        }

        return $input;
    }
}
