<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\MaintenanceScheduleImport;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MaintenanceScheduleController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'maintenance-schedule';

    /**
     * Process import files (excel, csv) into MaintenanceSchedule records.
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
                $import = new MaintenanceScheduleImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to process.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    /**
     * Pause a maintenance schedule.
     * POST /maintenance-schedules/{id}/pause.
     */
    public function pause(string $id): JsonResponse
    {
        $schedule = MaintenanceSchedule::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $schedule->pause();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Maintenance schedule paused.',
            'data'    => $schedule->fresh(),
        ]);
    }

    /**
     * Resume a paused maintenance schedule.
     * POST /maintenance-schedules/{id}/resume.
     */
    public function resume(string $id): JsonResponse
    {
        $schedule = MaintenanceSchedule::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $schedule->resume();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Maintenance schedule resumed.',
            'data'    => $schedule->fresh(),
        ]);
    }

    /**
     * Manually trigger a work order from a schedule.
     * POST /maintenance-schedules/{id}/trigger.
     */
    public function trigger(string $id, Request $request): JsonResponse
    {
        $schedule = MaintenanceSchedule::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $workOrder = WorkOrder::create([
            'company_uuid'    => $schedule->company_uuid,
            'schedule_uuid'   => $schedule->uuid,
            'subject'         => $schedule->name,
            'status'          => 'open',
            'priority'        => $schedule->default_priority ?? 'normal',
            'target_type'     => $schedule->subject_type,
            'target_uuid'     => $schedule->subject_uuid,
            'assignee_type'   => $schedule->default_assignee_type,
            'assignee_uuid'   => $schedule->default_assignee_uuid,
            'instructions'    => $schedule->instructions,
            'due_at'          => $schedule->next_due_date,
            'created_by_uuid' => session('user'),
        ]);

        return response()->json([
            'status'     => 'ok',
            'message'    => 'Work order created from schedule.',
            'work_order' => $workOrder,
        ]);
    }
}
