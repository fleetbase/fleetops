<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionLink as InspectionLinkResource;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\InspectionFormSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspectionFormController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'inspection-form';

    /**
     * The builder lays a form out before the form record exists, so the whole
     * structure arrives with the save that creates it and is written in one
     * go. `field_groups` is what this console posts; `draft` is what the fliit
     * builder posts, and is accepted so a form authored there still saves.
     *
     * A save that mentions no structure at all leaves the structure alone —
     * publishing a form, or renaming it, must not empty it.
     */
    public function onAfterCreate(Request $request, InspectionForm $inspectionForm): void
    {
        $this->syncStructureFromRequest($request, $inspectionForm);
    }

    public function onAfterUpdate(Request $request, InspectionForm $inspectionForm): void
    {
        $this->syncStructureFromRequest($request, $inspectionForm);
    }

    /** The console edits the structure, so a read has to carry it. */
    public function onFindRecord($builder, $request): void
    {
        $builder->with(['fieldGroups', 'fields']);
    }

    public function onQueryRecord($builder, $request): void
    {
        $builder->with(['fieldGroups', 'fields']);
    }

    /**
     * Writes the posted structure, pruning what the post no longer lists —
     * the builder always posts the whole form, so a field it dropped is a
     * field the author deleted.
     */
    protected function syncStructureFromRequest(Request $request, InspectionForm $form): void
    {
        $draft = null;
        foreach (['inspection_form.field_groups', 'field_groups', 'inspection_form.draft', 'draft'] as $key) {
            $posted = $request->input($key);
            if (is_array($posted) && !empty($posted)) {
                $draft = $posted;
                break;
            }
        }

        if ($draft === null) {
            return;
        }

        InspectionFormSync::sync($form, $draft, true);
        $form->unsetRelation('fieldGroups');
        $form->unsetRelation('fields');
        $form->load(['fieldGroups', 'fields']);
    }

    public function publish(string $id): JsonResponse
    {
        $form = $this->resolveForm($id)
            ->firstOrFail();

        $form->publish();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Inspection form published.',
            'data'    => $form->fresh(),
        ]);
    }

    public function archive(string $id): JsonResponse
    {
        $form = $this->resolveForm($id)
            ->firstOrFail();

        $form->archive();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Inspection form archived.',
            'data'    => $form->fresh(),
        ]);
    }

    public function generateLink(Request $request, string $id): JsonResponse
    {
        $form = $this->resolveForm($id)
            ->firstOrFail();

        if (!$form->is_published) {
            return response()->json([
                'error' => 'Inspection form must be published before generating a public link.',
            ], 422);
        }

        $validated = $request->validate([
            'driver'     => 'nullable|string',
            'vehicle'    => 'nullable|string',
            'expires_at' => 'nullable|date',
            'single_use' => 'nullable|boolean',
        ]);

        $driver  = $this->resolveDriver(data_get($validated, 'driver'));
        $vehicle = $this->resolveVehicle(data_get($validated, 'vehicle'));
        $token   = InspectionLink::generateToken();

        $link = InspectionLink::create([
            'company_uuid'         => $form->company_uuid,
            'inspection_form_uuid' => $form->uuid,
            'driver_uuid'          => $driver?->uuid,
            'vehicle_uuid'         => $vehicle?->uuid,
            'created_by_uuid'      => session('user'),
            'token_hash'           => InspectionLink::hashToken($token),
            'token'                => $token,
            'status'               => 'active',
            'single_use'           => data_get($validated, 'single_use', true),
            'expires_at'           => data_get($validated, 'expires_at'),
        ]);

        // `~/` is what puts the page outside the console: the host app routes
        // `/~/:slug` at the top level, a sibling of `console`, so neither the
        // console's chrome nor its authentication gate applies. Without it the
        // link lands on the authenticated `console/:slug` route instead, which
        // bounces a signed-out recipient to the login page and renders blank
        // for everyone else.
        $path = '/~/inspection?id=' . urlencode($form->public_id ?? $form->uuid) . '&token=' . urlencode($token);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Inspection link generated.',
            'link'    => array_merge(
                (new InspectionLinkResource($link->fresh(['form', 'driver', 'vehicle', 'createdBy'])))->resolve(),
                ['path' => $path, 'token' => $token]
            ),
        ]);
    }

    /**
     * Every link minted for this form, newest first.
     *
     * A link used to vanish the moment the modal that minted it closed. An
     * operator needs to see what is outstanding: which vehicle and driver a
     * link was for, when it was made, whether it has been opened, and whether
     * it still works.
     */
    public function links(Request $request, string $id): JsonResponse
    {
        $form = $this->resolveForm($id)->firstOrFail();

        $links = InspectionLink::where('inspection_form_uuid', $form->uuid)
            ->with(['form', 'driver', 'vehicle', 'createdBy'])
            ->orderByDesc('created_at')
            ->limit((int) $request->input('limit', 50))
            ->get();

        return response()->json([
            'links' => InspectionLinkResource::collection($links)->resolve(),
        ]);
    }

    /** Take a link out of use, leaving the record of it in the list. */
    public function revokeLink(Request $request, string $id, string $linkId): JsonResponse
    {
        $form = $this->resolveForm($id)->firstOrFail();

        $link = InspectionLink::where('inspection_form_uuid', $form->uuid)
            ->where(function ($query) use ($linkId) {
                $query->where('uuid', $linkId)->orWhere('public_id', $linkId);
            })
            ->firstOrFail();

        $link->revoke();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Inspection link revoked.',
            'link'    => (new InspectionLinkResource($link->fresh(['form', 'driver', 'vehicle', 'createdBy'])))->resolve(),
        ]);
    }

    protected function resolveDriver(?string $id): ?Driver
    {
        if (!$id) {
            return null;
        }

        return Driver::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();
    }

    protected function resolveForm(string $id)
    {
        return InspectionForm::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            });
    }

    protected function resolveVehicle(?string $id): ?Vehicle
    {
        if (!$id) {
            return null;
        }

        return Vehicle::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();
    }
}
