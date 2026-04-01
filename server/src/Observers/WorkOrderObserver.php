<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\WorkOrder;

/**
 * WorkOrderObserver.
 *
 * Listens for status transitions on WorkOrder records. When a work order is
 * closed (completed), this observer:
 *
 *  1. Automatically creates a Maintenance (history) record from the work order's
 *     data and any completion metadata captured in the work order's meta field.
 *  2. Resets the linked MaintenanceSchedule's next-due thresholds so the cycle
 *     restarts from the completion point.
 *  3. Dispatches a `work_order.completed` event for downstream integrations.
 */
class WorkOrderObserver
{
    /**
     * Handle the WorkOrder "updated" event.
     * We intercept the status transition to "closed" here rather than in a
     * dedicated "closed" event because Eloquent does not have a per-value event.
     */
    public function updated(WorkOrder $workOrder): void
    {
        // Only act when the status has just changed to "closed"
        if (!$workOrder->wasChanged('status') || $workOrder->status !== 'closed') {
            return;
        }

        $this->createMaintenanceRecord($workOrder);
        $this->resetSchedule($workOrder);

        event('work_order.completed', $workOrder);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create a Maintenance history record from the completed work order.
     */
    private function createMaintenanceRecord(WorkOrder $workOrder): void
    {
        // Avoid duplicate records if one was already manually created for this WO
        if (Maintenance::where('work_order_uuid', $workOrder->uuid)->exists()) {
            return;
        }

        $meta           = $workOrder->meta ?? [];
        $completionData = $meta['completion_data'] ?? [];

        Maintenance::create([
            'company_uuid'       => $workOrder->company_uuid,
            'work_order_uuid'    => $workOrder->uuid,
            'maintainable_type'  => $workOrder->target_type,
            'maintainable_uuid'  => $workOrder->target_uuid,
            'type'               => 'scheduled',
            'status'             => 'done',
            'priority'           => $workOrder->priority,
            'scheduled_at'       => $workOrder->opened_at,
            'completed_at'       => $workOrder->closed_at ?? now(),
            'performed_by_type'  => $workOrder->assignee_type,
            'performed_by_uuid'  => $workOrder->assignee_uuid,
            'summary'            => $workOrder->subject,
            'notes'              => $completionData['notes'] ?? null,
            'odometer'           => $completionData['odometer'] ?? null,
            'engine_hours'       => $completionData['engine_hours'] ?? null,
            'labor_cost'         => $completionData['labor_cost'] ?? null,
            'parts_cost'         => $completionData['parts_cost'] ?? null,
            'tax'                => $completionData['tax'] ?? null,
            'total_cost'         => $completionData['total_cost'] ?? null,
            'currency'           => $completionData['currency'] ?? null,
            'line_items'         => $completionData['line_items'] ?? null,
            'created_by_uuid'    => $workOrder->created_by_uuid,
        ]);
    }

    /**
     * Reset the linked schedule's next-due thresholds after completion.
     */
    private function resetSchedule(WorkOrder $workOrder): void
    {
        if (!$workOrder->schedule_uuid) {
            return;
        }

        $schedule = MaintenanceSchedule::where('uuid', $workOrder->schedule_uuid)->first();
        if (!$schedule) {
            return;
        }

        $meta           = $workOrder->meta ?? [];
        $completionData = $meta['completion_data'] ?? [];

        $schedule->resetAfterCompletion(
            odometer: isset($completionData['odometer']) ? (int) $completionData['odometer'] : null,
            completedEngineHours: isset($completionData['engine_hours']) ? (int) $completionData['engine_hours'] : null,
            completedAt: $workOrder->closed_at ?? now()
        );
    }
}
