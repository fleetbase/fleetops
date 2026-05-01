<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\Index\RecurringOrderSchedule as RecurringOrderScheduleIndexResource;
use Fleetbase\FleetOps\Http\Resources\v1\RecurringOrderSchedule as RecurringOrderScheduleResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\RecurringOrderSchedule;
use Fleetbase\FleetOps\Support\RecurringOrderMaterializationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class RecurringOrderScheduleController extends FleetOpsController
{
    public $resource = 'recurring-order-schedule';

    public $indexResource = RecurringOrderScheduleIndexResource::class;

    public function __construct(protected RecurringOrderMaterializationService $materializer)
    {
        parent::__construct();
    }

    public function createRecord(Request $request)
    {
        $validationError = $this->validateRecurringSchedulePayload($request);
        if ($validationError) {
            return $validationError;
        }

        return parent::createRecord($request);
    }

    public function updateRecord(Request $request, string $id)
    {
        $validationError = $this->validateRecurringSchedulePayload($request);
        if ($validationError) {
            return $validationError;
        }

        return parent::updateRecord($request, $id);
    }

    public function onAfterCreate($request, RecurringOrderSchedule $record, array $input): void
    {
        $this->materializer->materializeSchedule($record, now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));
    }

    public function onAfterUpdate($request, RecurringOrderSchedule $record, array $input): void
    {
        $this->materializer->materializeSchedule($record, now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));
    }

    public function onFindRecord($builder, $request): void
    {
        $builder->with(['customer', 'facilitator', 'orderConfig', 'driverAssigned', 'vehicleAssigned', 'serviceRate']);
    }

    public function pause(string $id): JsonResponse
    {
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $schedule->pause();

        return response()->json(['status' => 'ok', 'message' => 'Recurring order schedule paused.', 'data' => new RecurringOrderScheduleResource($schedule->fresh())]);
    }

    public function resume(string $id): JsonResponse
    {
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $schedule->resume();
        $this->materializer->materializeSchedule($schedule->fresh(), now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));

        return response()->json(['status' => 'ok', 'message' => 'Recurring order schedule resumed.', 'data' => new RecurringOrderScheduleResource($schedule->fresh())]);
    }

    public function cancelFuture(string $id, Request $request): JsonResponse
    {
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $cancelGenerated = $request->boolean('cancel_generated_orders', false);

        if ($cancelGenerated) {
            $schedule->generatedOrders()
                ->where('scheduled_at', '>=', now())
                ->whereNotIn('status', ['completed', 'canceled'])
                ->get()
                ->each(function (Order $order) {
                    $order->cancel();
                    $order->save();
                });
        }

        $schedule->cancelSchedule();

        return response()->json(['status' => 'ok', 'message' => 'Recurring order schedule canceled.', 'data' => new RecurringOrderScheduleResource($schedule->fresh())]);
    }

    public function skipOccurrence(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'occurrence_at' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
        ]);

        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $occurrence = $this->materializer->skipOccurrence(
            $schedule,
            Carbon::parse($request->input('occurrence_at'), $schedule->timezone ?: 'UTC'),
            $request->input('reason'),
            $request->boolean('cancel_generated_order', true)
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Occurrence canceled.',
            'occurrence' => $occurrence,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $input = $request->input('recurring_order_schedule', $request->all());
        $schedule = new RecurringOrderSchedule([
            'rrule' => $input['rrule'] ?? null,
            'timezone' => $input['timezone'] ?? 'UTC',
            'starts_at' => isset($input['starts_at']) ? Carbon::parse($input['starts_at']) : now(),
            'ends_at' => !empty($input['ends_at']) ? Carbon::parse($input['ends_at']) : null,
        ]);

        $limit = max(1, min((int) $request->input('limit', 10), 50));
        $occurrences = $schedule->previewOccurrences(now($schedule->timezone ?: 'UTC'), now($schedule->timezone ?: 'UTC')->addYears(1), $limit)
            ->map(fn (Carbon $occurrence) => [
                'occurrence_at' => $occurrence->copy()->setTimezone('UTC')->toISOString(),
                'occurrence_at_local' => $occurrence->toISOString(),
            ])
            ->values();

        return response()->json(['occurrences' => $occurrences]);
    }

    public function occurrences(string $id, Request $request): JsonResponse
    {
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $limit = max(1, min((int) $request->input('limit', 25), 100));

        return response()->json([
            'occurrences' => $this->buildUpcomingOccurrences($schedule, $limit),
        ]);
    }

    protected function validateRecurringSchedulePayload(Request $request): ?JsonResponse
    {
        $input = $request->input('recurring_order_schedule', $request->all());
        $validator = Validator::make($input, [
            'name' => ['required', 'string'],
            'rrule' => ['required', 'string'],
            'timezone' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'order' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->error($validator->errors()->all());
        }

        return null;
    }
}
