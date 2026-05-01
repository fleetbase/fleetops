<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\Index\RecurringOrderSchedule as RecurringOrderScheduleIndexResource;
use Fleetbase\FleetOps\Http\Resources\v1\RecurringOrderSchedule as RecurringOrderScheduleResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\RecurringOrderSchedule;
use Fleetbase\FleetOps\Support\RecurringOrderMaterializationService;
use Fleetbase\Support\Utils;
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

        $schedule = RecurringOrderSchedule::create($this->normalizeScheduleInput($input));
        $this->materializer->materializeSchedule($schedule, now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));

        return ['recurring_order_schedule' => new RecurringOrderScheduleResource($this->loadDetailRecord($schedule))];
    }

    public function updateRecord($id, Request $request)
    {
        $input = $request->input('recurring_order_schedule', $request->all());
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $schedule->update($this->normalizeScheduleInput($input, $schedule));
        $this->materializer->materializeSchedule($schedule->fresh(), now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));

        return ['recurring_order_schedule' => new RecurringOrderScheduleResource($this->loadDetailRecord($schedule->fresh()))];
    }

    public function findRecord($id, Request $request)
    {
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();

        return ['recurring_order_schedule' => new RecurringOrderScheduleResource($this->loadDetailRecord($schedule, (int) $request->input('upcoming_limit', 25)))];
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

    protected function normalizeScheduleInput(array $input, ?RecurringOrderSchedule $existing = null): array
    {
        $order = (array) ($input['order'] ?? []);
        $payload = (array) data_get($order, 'payload', []);

        return [
            'name' => data_get($input, 'name', $existing?->name),
            'description' => data_get($input, 'description', $existing?->description),
            'status' => data_get($input, 'status', $existing?->status ?? 'active'),
            'timezone' => data_get($input, 'timezone', $existing?->timezone ?? 'UTC'),
            'starts_at' => !empty($input['starts_at']) ? Carbon::parse($input['starts_at']) : $existing?->starts_at,
            'ends_at' => !empty($input['ends_at']) ? Carbon::parse($input['ends_at']) : null,
            'rrule' => data_get($input, 'rrule', $existing?->rrule),
            'company_uuid' => session('company', $existing?->company_uuid),
            'customer_uuid' => data_get($order, 'customer_uuid') ?: data_get($order, 'customer.id'),
            'customer_type' => data_get($order, 'customer_type'),
            'facilitator_uuid' => data_get($order, 'facilitator_uuid') ?: data_get($order, 'facilitator.id'),
            'facilitator_type' => data_get($order, 'facilitator_type'),
            'order_config_uuid' => data_get($order, 'order_config_uuid') ?: data_get($order, 'order_config.id'),
            'driver_assigned_uuid' => data_get($order, 'driver_assigned_uuid') ?: data_get($order, 'driver_assigned.id'),
            'vehicle_assigned_uuid' => data_get($order, 'vehicle_assigned_uuid') ?: data_get($order, 'vehicle_assigned.id'),
            'service_rate_uuid' => data_get($input, 'service_rate_uuid') ?: data_get($order, 'service_rate_uuid'),
            'template_order_meta' => [
                'internal_id' => data_get($order, 'internal_id'),
                'pod_method' => data_get($order, 'pod_method'),
                'pod_required' => (bool) data_get($order, 'pod_required', false),
                'adhoc' => (bool) data_get($order, 'adhoc', false),
                'adhoc_distance' => data_get($order, 'adhoc_distance'),
                'notes' => data_get($order, 'notes'),
                'type' => data_get($order, 'type'),
                'meta' => data_get($order, 'meta', []),
                'time_window_start' => data_get($order, 'time_window_start'),
                'time_window_end' => data_get($order, 'time_window_end'),
                'required_skills' => data_get($order, 'required_skills', []),
                'orchestrator_priority' => data_get($order, 'orchestrator_priority', 50),
            ],
            'template_payload' => [
                'pickup' => data_get($payload, 'pickup'),
                'dropoff' => data_get($payload, 'dropoff'),
                'return' => data_get($payload, 'return'),
                'waypoints' => array_values((array) data_get($payload, 'waypoints', [])),
                'type' => data_get($payload, 'type'),
                'payment_method' => data_get($payload, 'payment_method'),
                'cod_amount' => data_get($payload, 'cod_amount'),
                'cod_currency' => data_get($payload, 'cod_currency'),
                'cod_payment_method' => data_get($payload, 'cod_payment_method'),
                'meta' => data_get($payload, 'meta', []),
            ],
            'template_entities' => array_values((array) data_get($payload, 'entities', [])),
            'meta' => array_merge((array) data_get($existing, 'meta', []), (array) data_get($input, 'meta', [])),
            'updated_by_uuid' => session('user'),
            'created_by_uuid' => $existing?->created_by_uuid ?: session('user'),
        ];
    }

    protected function loadDetailRecord(RecurringOrderSchedule $schedule, int $upcomingLimit = 25): RecurringOrderSchedule
    {
        $schedule->load(['customer', 'facilitator', 'orderConfig', 'driverAssigned', 'vehicleAssigned', 'serviceRate']);
        $meta = (array) ($schedule->meta ?? []);
        $meta['upcoming_occurrences'] = $this->buildUpcomingOccurrences($schedule, $upcomingLimit);
        $schedule->meta = $meta;

        return $schedule;
    }

    protected function buildUpcomingOccurrences(RecurringOrderSchedule $schedule, int $limit = 25): array
    {
        $timezone = $schedule->timezone ?: 'UTC';
        $preview = $schedule->previewOccurrences(now($timezone), now($timezone)->addYears(1), $limit);
        $states = $schedule->occurrences()
            ->where('occurrence_at', '>=', now())
            ->with('order')
            ->get()
            ->keyBy(fn ($occurrence) => $occurrence->occurrence_at->toISOString());

        return $preview->map(function (Carbon $occurrence) use ($states) {
            $occurrenceUtc = $occurrence->copy()->setTimezone('UTC');
            $state = $states->get($occurrenceUtc->toISOString());

            return [
                'occurrence_at' => $occurrenceUtc->toISOString(),
                'occurrence_at_local' => $occurrence->toISOString(),
                'status' => $state?->status ?? 'scheduled',
                'reason' => $state?->reason,
                'order' => $state?->order ? [
                    'id' => $state->order->public_id,
                    'public_id' => $state->order->public_id,
                    'status' => $state->order->status,
                    'scheduled_at' => $state->order->scheduled_at,
                ] : null,
            ];
        })->values()->all();
    }
}
