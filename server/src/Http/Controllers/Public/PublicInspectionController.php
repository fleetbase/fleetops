<?php

namespace Fleetbase\FleetOps\Http\Controllers\Public;

use Fleetbase\FleetOps\Http\Resources\v1\InspectionForm as InspectionFormResource;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionSubmission as InspectionSubmissionResource;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PublicInspectionController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        [$form, $link] = $this->resolvePublishedFormAndLink($request, $id);

        $link->markViewed();

        return response()->json([
            'form'     => (new InspectionFormResource($form))->resolve(),
            'identity' => $this->identityPayload($link),
        ]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        [$form, $link] = $this->resolvePublishedFormAndLink($request, $id);

        $validated = $request->validate([
            'odometer'       => 'nullable|integer|min:0',
            'engine_hours'   => 'nullable|integer|min:0',
            'item_results'   => 'required|array|min:1',
            'item_results.*.item_key' => 'nullable|string|max:191',
            'item_results.*.label'    => 'required|string|max:255',
            'item_results.*.category' => 'nullable|string|max:191',
            'item_results.*.status'   => 'nullable|string|max:50',
            'item_results.*.severity' => 'nullable|string|max:50',
            'item_results.*.passed'   => 'required|boolean',
            'item_results.*.comments' => 'nullable|string|max:2000',
            'item_results.*.photos'   => 'nullable|array',
            'location'       => 'nullable|array',
            'signature'      => 'nullable|array',
            'attachments'    => 'nullable|array',
        ]);

        $submission = InspectionSubmission::create([
            'company_uuid'          => $form->company_uuid,
            'inspection_form_uuid'  => $form->uuid,
            'vehicle_uuid'          => $link->vehicle_uuid,
            'driver_uuid'           => $link->driver_uuid,
            'submitted_by_uuid'     => $link->driver?->user_uuid,
            'type'                  => $form->type ?? 'dvir',
            'status'                => 'submitted',
            'source'                => 'public_link',
            'odometer'              => data_get($validated, 'odometer'),
            'engine_hours'          => data_get($validated, 'engine_hours'),
            'started_at'            => now(),
            'submitted_at'          => now(),
            'location'              => data_get($validated, 'location'),
            'signature'             => data_get($validated, 'signature'),
            'attachments'           => data_get($validated, 'attachments'),
            'meta'                  => [
                'inspection_link_uuid' => $link->uuid,
                'inspection_link_id'   => $link->public_id,
            ],
        ]);

        foreach ($validated['item_results'] as $item) {
            InspectionItemResult::create([
                'company_uuid'                => $form->company_uuid,
                'inspection_submission_uuid'  => $submission->uuid,
                'item_key'                    => data_get($item, 'item_key'),
                'label'                       => data_get($item, 'label'),
                'category'                    => data_get($item, 'category'),
                'status'                      => data_get($item, 'status', data_get($item, 'passed') ? 'passed' : 'failed'),
                'severity'                    => data_get($item, 'severity'),
                'passed'                      => (bool) data_get($item, 'passed'),
                'comments'                    => data_get($item, 'comments'),
                'photos'                      => data_get($item, 'photos'),
            ]);
        }

        $submission->syncResultCounts();

        if (data_get($form->settings, 'create_issue_on_failure') && $submission->has_failures) {
            $submission->createIssueFromFailures();
        }

        if (data_get($form->settings, 'create_work_order_on_failure') && $submission->has_failures) {
            $submission->createWorkOrderFromFailures();
        }

        $link->markUsed($request->ip(), (string) $request->userAgent());

        return response()->json([
            'message'    => 'Inspection submitted.',
            'submission' => (new InspectionSubmissionResource($submission->fresh(['form', 'vehicle', 'driver', 'itemResults', 'issue', 'workOrder'])))->resolve(),
        ]);
    }

    protected function resolvePublishedFormAndLink(Request $request, string $id): array
    {
        $token = (string) $request->query('token', $request->input('token'));
        if (empty($token)) {
            abort(response()->json(['error' => 'Inspection token is required.'], 403));
        }

        $form = InspectionForm::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        if (!$form->is_published) {
            abort(response()->json(['error' => 'This inspection form is not available.'], 403));
        }

        $link = InspectionLink::where('inspection_form_uuid', $form->uuid)
            ->where('token_hash', InspectionLink::hashToken($token))
            ->first();

        if (!$link) {
            abort(response()->json(['error' => 'Inspection link is invalid.'], 403));
        }

        if (!$link->isUsable()) {
            abort(response()->json(['error' => 'Inspection link is expired, revoked, or already used.'], 403));
        }

        return [$form, $link];
    }

    protected function identityPayload(InspectionLink $link): array
    {
        return [
            'driver' => $link->driver ? [
                'id'        => $link->driver->public_id,
                'name'      => $link->driver->name,
                'phone'     => $link->driver->phone,
            ] : null,
            'vehicle' => $link->vehicle ? [
                'id'           => $link->vehicle->public_id,
                'name'         => $link->vehicle->display_name ?? $link->vehicle->name,
                'plate_number' => $link->vehicle->plate_number,
            ] : null,
            'expires_at' => $link->expires_at,
        ];
    }
}
