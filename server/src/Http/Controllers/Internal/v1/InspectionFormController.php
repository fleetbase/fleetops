<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\InspectionForm;
use Illuminate\Http\JsonResponse;

class InspectionFormController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'inspection-form';

    public function publish(string $id): JsonResponse
    {
        $form = InspectionForm::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $form->publish();

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection form published.',
            'data' => $form->fresh(),
        ]);
    }

    public function archive(string $id): JsonResponse
    {
        $form = InspectionForm::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $form->archive();

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection form archived.',
            'data' => $form->fresh(),
        ]);
    }
}
