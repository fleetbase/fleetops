<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\MaintenanceScheduleImport;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Spatie\IcalendarGenerator\Enums\RecurrenceFrequency;
use Spatie\IcalendarGenerator\ValueObjects\RRule;

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

    /**
     * Return a JSON calendar feed of upcoming maintenance schedule events.
     *
     * Accepts optional `start` and `end` query params (ISO 8601 date strings)
     * to limit the window. Defaults to the next 90 days.
     *
     * GET /maintenance-schedules/calendar-feed
     */
    public function calendarFeed(Request $request): JsonResponse
    {
        $start = $request->input('start')
            ? Carbon::parse($request->input('start'))->startOfDay()
            : Carbon::today();

        $end = $request->input('end')
            ? Carbon::parse($request->input('end'))->endOfDay()
            : Carbon::today()->addDays(90)->endOfDay();

        $schedules = MaintenanceSchedule::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$start, $end])
            ->whereNull('deleted_at')
            ->with(['subject', 'defaultAssignee'])
            ->get();

        $events = $schedules->map(function (MaintenanceSchedule $schedule) {
            $assetName = $schedule->subject?->name
                ?? $schedule->subject?->display_name
                ?? $schedule->subject?->public_id
                ?? 'Unknown Asset';

            $assigneeName = $schedule->defaultAssignee?->name ?? null;

            return [
                'id'            => $schedule->public_id,
                'uuid'          => $schedule->uuid,
                'title'         => $schedule->name . ' — ' . $assetName,
                'start'         => $schedule->next_due_date?->toDateString(),
                'end'           => $schedule->next_due_date?->toDateString(),
                'allDay'        => true,
                'status'        => $schedule->status,
                'priority'      => $schedule->default_priority,
                'type'          => $schedule->type,
                'subject_name'  => $assetName,
                'assignee_name' => $assigneeName,
                'color'         => $this->eventColorForPriority($schedule->default_priority),
            ];
        });

        return response()->json(['events' => $events]);
    }

    /**
     * Download an iCal (.ics) file for a single maintenance schedule.
     *
     * GET /maintenance-schedules/{id}/ical
     */
    public function ical(string $id): Response
    {
        $schedule = MaintenanceSchedule::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->with(['subject', 'defaultAssignee'])
            ->firstOrFail();

        $assetName = $schedule->subject?->name
            ?? $schedule->subject?->display_name
            ?? $schedule->subject?->public_id
            ?? 'Asset';

        $eventTitle = $schedule->name . ' — ' . $assetName;

        $dueDate = $schedule->next_due_date ?? Carbon::today();

        $description = implode("\n", array_filter([
            'Schedule: ' . $schedule->name,
            'Asset: ' . $assetName,
            'Type: ' . ucfirst(str_replace('_', ' ', $schedule->type ?? '')),
            'Priority: ' . ucfirst($schedule->default_priority ?? 'normal'),
            $schedule->instructions ? 'Instructions: ' . $schedule->instructions : null,
        ]));

        // Build the event, adding an RRULE when the schedule has a time-based interval.
        $event = Event::create($eventTitle)
            ->uniqueIdentifier($schedule->uuid . '@fleetbase.io')
            ->description($description)
            ->startsAt($dueDate->copy()->startOfDay())
            ->endsAt($dueDate->copy()->endOfDay())
            ->fullDay();

        $intervalValue = (int) ($schedule->interval_value ?? 0);
        $intervalUnit  = $schedule->interval_unit ?? null;

        if ($intervalValue > 0 && $intervalUnit) {
            $freqMap = [
                'days'   => RecurrenceFrequency::daily(),
                'weeks'  => RecurrenceFrequency::weekly(),
                'months' => RecurrenceFrequency::monthly(),
                'years'  => RecurrenceFrequency::yearly(),
            ];
            $freq = $freqMap[$intervalUnit] ?? null;
            if ($freq !== null) {
                $rrule = RRule::frequency($freq)->interval($intervalValue);
                $event->rrule($rrule);
            }
        }

        $calendar = Calendar::create($eventTitle)
            ->productIdentifier('Fleetbase FleetOps')
            ->event($event);

        $filename = 'maintenance-' . $schedule->public_id . '.ics';

        return response($calendar->get(), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Map a priority string to a FullCalendar-compatible hex colour.
     */
    private function eventColorForPriority(?string $priority): string
    {
        return match ($priority) {
            'critical'  => '#ef4444',
            'high'      => '#f97316',
            'normal'    => '#3b82f6',
            'low'       => '#22c55e',
            default     => '#6b7280',
        };
    }
}
