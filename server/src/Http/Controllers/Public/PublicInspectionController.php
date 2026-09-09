<?php

namespace Fleetbase\FleetOps\Http\Controllers\Public;

use Fleetbase\FleetOps\Http\Resources\v1\InspectionForm as InspectionFormResource;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionSubmission as InspectionSubmissionResource;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Support\InspectionSubmitter;
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

        $validated = $request->validate(InspectionSubmitter::rules());

        $submission = InspectionSubmitter::submit($form, $validated, [
            'vehicle_uuid'      => $link->vehicle_uuid,
            'driver_uuid'       => $link->driver_uuid,
            'submitted_by_uuid' => $link->driver?->user_uuid,
            'source'            => 'public_link',
            'meta'              => [
                'inspection_link_uuid' => $link->uuid,
                'inspection_link_id'   => $link->public_id,
            ],
        ]);

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
