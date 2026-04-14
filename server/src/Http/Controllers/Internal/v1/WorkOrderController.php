<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\WorkOrderImport;
use Fleetbase\FleetOps\Mail\WorkOrderDispatched;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class WorkOrderController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'work-order';

    /**
     * Process import files (excel, csv) into WorkOrder records.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk          = $request->input('disk', config('filesystems.default'));
        $files         = $request->resolveFilesFromIds();
        $importedCount = 0;

        foreach ($files as $file) {
            try {
                $import = new WorkOrderImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to process.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    /**
     * Send a work order email to the assigned vendor.
     * POST /work-orders/{id}/send.
     */
    public function sendEmail(string $id): JsonResponse
    {
        $workOrder = WorkOrder::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->with(['assignee', 'target'])
            ->firstOrFail();

        // Resolve recipient email from the assignee (vendor or contact)
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
}
