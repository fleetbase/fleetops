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
     * @return Response
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
     * to limit the visible window. Defaults to the next 90 days.
     *
     * For recurring schedules (those with interval_value + interval_unit set)
     * we expand all occurrences that fall inside the requested window, not just
     * the single stored next_due_date. This is what makes navigating to future
     * calendar months work correctly.
     *
     * GET /maintenance-schedules/calendar-feed
     */
    public function calendarFeed(Request $request): JsonResponse
    {
        $windowStart = $request->input('start')
            ? Carbon::parse($request->input('start'))->startOfDay()
            : Carbon::today();

        $windowEnd = $request->input('end')
            ? Carbon::parse($request->input('end'))->endOfDay()
            : Carbon::today()->addDays(90)->endOfDay();

        // Fetch all active schedules whose next_due_date is on or before the
        // window end. Schedules that start after the window end can never
        // produce an occurrence inside the window.
        $schedules = MaintenanceSchedule::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $windowEnd)
            ->whereNull('deleted_at')
            ->with(['subject', 'defaultAssignee'])
            ->get();

        $events = [];

        foreach ($schedules as $schedule) {
            $assetName = $schedule->subject?->name
                ?? $schedule->subject?->display_name
                ?? $schedule->subject?->public_id
                ?? 'Unknown Asset';

            $assigneeName = $schedule->defaultAssignee?->name ?? null;
            $color        = $this->eventColorForPriority($schedule->default_priority);

            $baseEvent = [
                'id'            => $schedule->public_id,
                'uuid'          => $schedule->uuid,
                'title'         => $schedule->name . ' — ' . $assetName,
                'allDay'        => true,
                'status'        => $schedule->status,
                'priority'      => $schedule->default_priority,
                'type'          => $schedule->type,
                'subject_name'  => $assetName,
                'assignee_name' => $assigneeName,
                'color'         => $color,
            ];

            $intervalValue = (int) ($schedule->interval_value ?? 0);
            $intervalUnit  = $schedule->interval_unit ?? null; // days | weeks | months | years
            $firstDue      = $schedule->next_due_date->copy()->startOfDay();

            if ($intervalValue > 0 && $intervalUnit) {
                // --- Recurring schedule: expand all occurrences in the window ---
                //
                // Walk forward from next_due_date in steps of (interval_value interval_unit)
                // and emit an event for every occurrence that falls inside [windowStart, windowEnd].
                // We cap at 500 occurrences as a safety guard.
                $occurrence     = $firstDue->copy();
                $count          = 0;
                $maxOccurrences = 500;

                while ($occurrence->lte($windowEnd) && $count < $maxOccurrences) {
                    if ($occurrence->gte($windowStart)) {
                        $dateStr  = $occurrence->toDateString();
                        $events[] = array_merge($baseEvent, [
                            // Keep id as the plain public_id so the click
                            // handler can navigate to the schedule details.
                            'id'              => $schedule->public_id,
                            'start'           => $dateStr,
                            'end'             => $dateStr,
                            'occurrence_date' => $dateStr,
                        ]);
                    }
                    $occurrence->add($intervalValue . ' ' . $intervalUnit);
                    $count++;
                }
            } else {
                // --- One-off schedule: emit only if it falls in the window ---
                if ($firstDue->between($windowStart, $windowEnd)) {
                    $dateStr  = $firstDue->toDateString();
                    $events[] = array_merge($baseEvent, [
                        'start' => $dateStr,
                        'end'   => $dateStr,
                    ]);
                }
            }
        }

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
