<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspectionSubmissionController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'inspection-submission';

    public function onAfterCreate($request, InspectionSubmission $record, array $input): void
    {
        $this->syncItemResultsFromRequest($request, $record);
        $record->load(['form', 'vehicle', 'driver', 'itemResults']);
    }

    public function onAfterUpdate($request, InspectionSubmission $record, array $input): void
    {
        $this->syncItemResultsFromRequest($request, $record);
        $record->load(['form', 'vehicle', 'driver', 'itemResults']);
    }

    public function onFindRecord($builder, $request): void
    {
        $builder->with(['form', 'vehicle', 'driver', 'submittedBy', 'issue', 'workOrder', 'itemResults']);
    }

    public function submit(string $id): JsonResponse
    {
        $submission = InspectionSubmission::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->with(['itemResults', 'vehicle', 'driver', 'form'])
            ->firstOrFail();

        $submission->syncResultCounts();

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection submitted.',
            'data' => $submission->fresh(['itemResults', 'vehicle', 'driver', 'form']),
        ]);
    }

    public function createIssue(string $id): JsonResponse
    {
        $submission = InspectionSubmission::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->with(['itemResults', 'vehicle', 'driver', 'form'])
            ->firstOrFail();

        $submission->syncResultCounts();
        $issue = $submission->createIssueFromFailures();

        return response()->json([
            'status' => 'ok',
            'message' => $issue ? 'Issue created from failed inspection items.' : 'No failed inspection items found.',
            'issue' => $issue,
            'data' => $submission->fresh(['itemResults', 'issue']),
        ]);
    }

    public function createWorkOrder(string $id): JsonResponse
    {
        $submission = InspectionSubmission::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->with(['itemResults', 'vehicle', 'driver', 'form'])
            ->firstOrFail();

        $submission->syncResultCounts();
        $submission->createIssueFromFailures();
        $workOrder = $submission->createWorkOrderFromFailures();

        return response()->json([
            'status' => 'ok',
            'message' => $workOrder ? 'Work order created from failed inspection items.' : 'No failed inspection items found.',
            'work_order' => $workOrder,
            'data' => $submission->fresh(['itemResults', 'issue', 'workOrder']),
        ]);
    }

    public function resolve(string $id): JsonResponse
    {
        $submission = InspectionSubmission::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $submission->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection resolved.',
            'data' => $submission->fresh(['itemResults', 'issue', 'workOrder']),
        ]);
    }

    protected function syncItemResultsFromRequest(Request $request, InspectionSubmission $submission): void
    {
        $items = $request->array('inspection_submission.item_results', $request->array('item_results'));
        if (empty($items)) {
            return;
        }

        $seen = [];
        foreach ($items as $item) {
            $uuid = data_get($item, 'uuid');
            $payload = [
                'company_uuid' => $submission->company_uuid,
                'inspection_submission_uuid' => $submission->uuid,
                'item_key' => data_get($item, 'item_key'),
                'label' => data_get($item, 'label', data_get($item, 'title', 'Inspection item')),
                'category' => data_get($item, 'category'),
                'status' => data_get($item, 'status', data_get($item, 'passed') === false ? 'failed' : 'passed'),
                'severity' => data_get($item, 'severity'),
                'passed' => (bool) data_get($item, 'passed', data_get($item, 'status') !== 'failed'),
                'comments' => data_get($item, 'comments'),
                'photos' => data_get($item, 'photos'),
                'meta' => data_get($item, 'meta'),
            ];

            $lookup = [
                'inspection_submission_uuid' => $submission->uuid,
            ];

            if ($uuid) {
                $lookup['uuid'] = $uuid;
            } elseif ($payload['item_key']) {
                $lookup['item_key'] = $payload['item_key'];
            } else {
                $lookup['label'] = $payload['label'];
            }

            $result = InspectionItemResult::updateOrCreate($lookup, $payload);
            $seen[] = $result->uuid;
        }

        if (!empty($seen)) {
            $submission->itemResults()->whereNotIn('uuid', $seen)->delete();
        }

        $submission->syncResultCounts();
    }
}
