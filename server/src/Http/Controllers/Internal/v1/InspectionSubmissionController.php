<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\InspectionExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Support\InspectionSubmitter;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InspectionSubmissionController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'inspection-submission';

    /** What a submission has to carry for the console record to render. */
    protected const RELATIONS = ['form', 'vehicle', 'driver', 'itemResults', 'customFieldValues.customField', 'files'];

    public function onAfterCreate($request, InspectionSubmission $record, array $input): void
    {
        $this->syncAnswersFromRequest($request, $record);
        $record->load(static::RELATIONS);
    }

    public function onAfterUpdate($request, InspectionSubmission $record, array $input): void
    {
        $this->syncAnswersFromRequest($request, $record);
        $record->load(static::RELATIONS);
    }

    public function onFindRecord($builder, $request): void
    {
        $builder->with(array_merge(['submittedBy', 'issue', 'workOrder'], static::RELATIONS));
    }

    public function onQueryRecord($builder, $request): void
    {
        $builder->with(['form', 'vehicle', 'driver', 'itemResults']);
    }

    /**
     * The answers, then the results derived from them.
     *
     * A form built from fields is answered with `custom_field_values`, exactly
     * as the driver API answers it, so the console and the app write the same
     * rows; the submitter stores any photo or signature and mirrors every
     * pass-fail answer into an item result. A submission against a legacy
     * checklist still posts `item_results` directly, and that door stays open.
     */
    protected function syncAnswersFromRequest(Request $request, InspectionSubmission $submission): void
    {
        $values = static::arrayInput($request, 'inspection_submission.custom_field_values', 'custom_field_values');
        if (!empty($values)) {
            InspectionSubmitter::applyCustomFieldValues($submission, $values, session('user'));

            return;
        }

        $this->syncItemResultsFromRequest($request, $submission);
    }

    /**
     * The first of the given keys that carries a list.
     *
     * The console posts a record under its resource name and the app posts it
     * flat, so both spellings are read. `Request::array()` arrived in Laravel
     * 11 and this runs on 10, where calling it is a BadMethodCallException.
     *
     * @return array<int, mixed>
     */
    protected static function arrayInput(Request $request, string ...$keys): array
    {
        foreach ($keys as $key) {
            $value = $request->input($key);
            if (is_array($value) && !empty($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * Export inspections to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format     = $request->input('format', 'xlsx');
        $selections = static::arrayInput($request, 'selections');
        $fileName   = trim(Str::slug('inspections-' . date('Y-m-d-H:i')) . '.' . $format);

        return $this->downloadExport(new InspectionExport($selections), $fileName);
    }

    public function submit(string $id): JsonResponse
    {
        $submission = InspectionSubmission::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->with(['itemResults', 'vehicle', 'driver', 'form'])
            ->firstOrFail();

        $submission->syncResultCounts();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Inspection submitted.',
            'data'    => $submission->fresh(['itemResults', 'vehicle', 'driver', 'form']),
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
            'status'  => 'ok',
            'message' => $issue ? 'Issue created from failed inspection items.' : 'No failed inspection items found.',
            'issue'   => $issue,
            'data'    => $submission->fresh(['itemResults', 'issue']),
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
            'status'     => 'ok',
            'message'    => $workOrder ? 'Work order created from failed inspection items.' : 'No failed inspection items found.',
            'work_order' => $workOrder,
            'data'       => $submission->fresh(['itemResults', 'issue', 'workOrder']),
        ]);
    }

    public function resolve(string $id): JsonResponse
    {
        $submission = InspectionSubmission::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $submission->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Inspection resolved.',
            'data'    => $submission->fresh(['itemResults', 'issue', 'workOrder']),
        ]);
    }

    protected function syncItemResultsFromRequest(Request $request, InspectionSubmission $submission): void
    {
        $items = static::arrayInput($request, 'inspection_submission.item_results', 'item_results');
        if (empty($items)) {
            return;
        }

        $seen = [];
        foreach ($items as $item) {
            $uuid    = data_get($item, 'uuid');
            $payload = [
                'company_uuid'               => $submission->company_uuid,
                'inspection_submission_uuid' => $submission->uuid,
                'item_key'                   => data_get($item, 'item_key'),
                'label'                      => data_get($item, 'label', data_get($item, 'title', 'Inspection item')),
                'category'                   => data_get($item, 'category'),
                'status'                     => data_get($item, 'status', data_get($item, 'passed') === false ? 'failed' : 'passed'),
                'severity'                   => data_get($item, 'severity'),
                'passed'                     => (bool) data_get($item, 'passed', data_get($item, 'status') !== 'failed'),
                'comments'                   => data_get($item, 'comments'),
                'photos'                     => data_get($item, 'photos'),
                'meta'                       => data_get($item, 'meta'),
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
