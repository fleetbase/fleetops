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
    public $resource      = 'recurring-order-schedule';
    public $indexResource = RecurringOrderScheduleIndexResource::class;

    public function __construct(protected RecurringOrderMaterializationService $materializer)
    {
        parent::__construct();
        $this->materializer = $materializer;
    }

    public function createRecord(Request $request)
    {
        $validationError = $this->validateRecurringSchedulePayload($request, true);
        if ($validationError) {
            return $validationError;
        }

        try {
            $record = $this->model->createRecordFromRequest(
                $request,
                function (Request $request, array &$input) {
                    return $this->onBeforeCreate($request, $input);
                },
                function (Request $request, RecurringOrderSchedule $record, array $input) {
                    return $this->onAfterCreate($request, $record, $input);
                }
            );

            return new $this->resource($record);
        } catch (\Throwable $e) {
            return response()->error(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Error occurred while trying to create a recurring order schedule');
        }
    }

    public function updateRecord(Request $request, string $id)
    {
        $validationError = $this->validateRecurringSchedulePayload($request, false);
        if ($validationError) {
            return $validationError;
        }

        try {
            $record = $this->model->updateRecordFromRequest(
                $request,
                $id,
                function (Request $request, RecurringOrderSchedule $record, array &$input) {
                    return $this->onBeforeUpdate($request, $record, $input);
                },
                function (Request $request, RecurringOrderSchedule $record, array $input) {
                    return $this->onAfterUpdate($request, $record, $input);
                }
            );

            return new $this->resource($record);
        } catch (\Throwable $e) {
            return response()->error(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Error occurred while trying to update a recurring order schedule');
        }
    }

    public function onBeforeCreate(Request $request, array &$input): ?JsonResponse
    {
        $input = $this->normalizeRecurringSeriesInput($input);

        return null;
    }

    public function onBeforeUpdate(Request $request, RecurringOrderSchedule $record, array &$input): ?JsonResponse
    {
        $input = $this->normalizeRecurringSeriesInput($input, $record);

        return null;
    }

    public function onAfterCreate($request, RecurringOrderSchedule $record, array $input): void
    {
        $this->materializer->materializeSchedule($record, now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));
    }

    public function onAfterUpdate($request, RecurringOrderSchedule $record, array $input): void
    {
        $this->materializer->materializeSchedule($record, now()->addDays((int) config('fleetops.recurring_orders.horizon_days', 60)));
    }

    public function onQueryRecord($builder, $request): void
    {
        $builder->with(['customer', 'orderConfig', 'serviceRate']);
        $builder->withCount('generatedOrders');
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
        $schedule        = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
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
            'reason'        => ['nullable', 'string'],
        ]);

        $schedule   = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $occurrence = $this->materializer->skipOccurrence(
            $schedule,
            Carbon::parse($request->input('occurrence_at'), $schedule->timezone ?: 'UTC'),
            $request->input('reason'),
            $request->boolean('cancel_generated_order', true)
        );

        return response()->json([
            'status'     => 'ok',
            'message'    => 'Occurrence canceled.',
            'occurrence' => $occurrence,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $input    = $request->input('recurring_order_schedule', $request->all());
        $schedule = new RecurringOrderSchedule([
            'rrule'     => $input['rrule'] ?? null,
            'timezone'  => $input['timezone'] ?? 'UTC',
            'starts_at' => isset($input['starts_at']) ? Carbon::parse($input['starts_at']) : now(),
            'ends_at'   => !empty($input['ends_at']) ? Carbon::parse($input['ends_at']) : null,
        ]);

        $limit       = max(1, min((int) $request->input('limit', 10), 50));
        $occurrences = $schedule->previewOccurrences(now($schedule->timezone ?: 'UTC'), now($schedule->timezone ?: 'UTC')->addYears(1), $limit)
            ->map(fn (Carbon $occurrence) => [
                'occurrence_at'       => $occurrence->copy()->setTimezone('UTC')->toISOString(),
                'occurrence_at_local' => $occurrence->toISOString(),
            ])
            ->values();

        return response()->json(['occurrences' => $occurrences]);
    }

    public function occurrences(string $id, Request $request): JsonResponse
    {
        $schedule = RecurringOrderSchedule::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $limit    = max(1, min((int) $request->input('limit', 25), 100));
        $scope    = $request->input('scope', 'upcoming');

        return response()->json([
            'occurrences' => $scope === 'history' ? $schedule->getOccurrenceHistory($limit) : $schedule->getUpcomingOccurrences($limit),
        ]);
    }

    protected function validateRecurringSchedulePayload(Request $request, bool $creating): ?JsonResponse
    {
        $input     = $request->input('recurring_order_schedule', $request->all());
        $validator = Validator::make($input, [
            'name'                  => [$creating ? 'required' : 'sometimes', 'string'],
            'rrule'                 => [$creating ? 'required' : 'sometimes', 'string'],
            'timezone'              => [$creating ? 'required' : 'sometimes', 'string'],
            'starts_at'             => [$creating ? 'required' : 'sometimes', 'date'],
            'order'                 => [$creating ? 'required' : 'sometimes', 'array'],
            'order.payload.pickup'  => [$creating ? 'required' : 'sometimes'],
            'order.payload.dropoff' => [$creating ? 'required' : 'sometimes'],
        ]);

        if ($validator->fails()) {
            return response()->error($validator->errors()->all());
        }

        return null;
    }

    protected function normalizeRecurringSeriesInput(array $input, ?RecurringOrderSchedule $existing = null): array
    {
        $order   = (array) ($input['order'] ?? []);
        $payload = (array) data_get($order, 'payload', []);

        if (!$existing && empty($order)) {
            throw new \Exception('Recurring series requires an order template.');
        }

        $templatePayload = [
            'pickup'             => $this->compactTemplatePlace(data_get($payload, 'pickup', data_get($existing?->template_payload, 'pickup'))),
            'dropoff'            => $this->compactTemplatePlace(data_get($payload, 'dropoff', data_get($existing?->template_payload, 'dropoff'))),
            'return'             => $this->compactTemplatePlace(data_get($payload, 'return', data_get($existing?->template_payload, 'return'))),
            'waypoints'          => array_values(array_filter(array_map(fn ($waypoint) => $this->compactTemplateWaypoint($waypoint), (array) data_get($payload, 'waypoints', data_get($existing?->template_payload, 'waypoints', []))))),
            'type'               => data_get($payload, 'type', data_get($existing?->template_payload, 'type')),
            'payment_method'     => data_get($payload, 'payment_method', data_get($existing?->template_payload, 'payment_method')),
            'cod_amount'         => data_get($payload, 'cod_amount', data_get($existing?->template_payload, 'cod_amount')),
            'cod_currency'       => data_get($payload, 'cod_currency', data_get($existing?->template_payload, 'cod_currency')),
            'cod_payment_method' => data_get($payload, 'cod_payment_method', data_get($existing?->template_payload, 'cod_payment_method')),
            'meta'               => $this->compactTemplateMeta(data_get($payload, 'meta', data_get($existing?->template_payload, 'meta', []))),
        ];

        if (empty($templatePayload['pickup']) || empty($templatePayload['dropoff'])) {
            throw new \Exception('Recurring series requires pickup and dropoff template locations.');
        }

        return [
            'name'                   => data_get($input, 'name', $existing?->name),
            'description'            => data_get($input, 'description', $existing?->description),
            'status'                 => data_get($input, 'status', $existing?->status ?? 'active'),
            'timezone'               => data_get($input, 'timezone', $existing?->timezone ?? 'UTC'),
            'starts_at'              => !empty($input['starts_at']) ? Carbon::parse($input['starts_at']) : $existing?->starts_at,
            'ends_at'                => !empty($input['ends_at']) ? Carbon::parse($input['ends_at']) : $existing?->ends_at,
            'rrule'                  => data_get($input, 'rrule', $existing?->rrule),
            'company_uuid'           => session('company', $existing?->company_uuid),
            'customer_uuid'          => data_get($order, 'customer_uuid') ?: data_get($order, 'customer.id') ?: $existing?->customer_uuid,
            'customer_type'          => data_get($order, 'customer_type', $existing?->customer_type),
            'facilitator_uuid'       => data_get($order, 'facilitator_uuid') ?: data_get($order, 'facilitator.id') ?: $existing?->facilitator_uuid,
            'facilitator_type'       => data_get($order, 'facilitator_type', $existing?->facilitator_type),
            'order_config_uuid'      => data_get($order, 'order_config_uuid') ?: data_get($order, 'order_config.id') ?: $existing?->order_config_uuid,
            'driver_assigned_uuid'   => data_get($order, 'driver_assigned_uuid') ?: data_get($order, 'driver_assigned.id') ?: $existing?->driver_assigned_uuid,
            'vehicle_assigned_uuid'  => data_get($order, 'vehicle_assigned_uuid') ?: data_get($order, 'vehicle_assigned.id') ?: $existing?->vehicle_assigned_uuid,
            'service_rate_uuid'      => data_get($input, 'service_rate_uuid') ?: data_get($order, 'service_rate_uuid') ?: $existing?->service_rate_uuid,
            'template_order_meta'    => $this->compactTemplateOrderMeta($order, $existing),
            'template_payload'       => $templatePayload,
            'template_entities'      => array_values(array_filter(array_map(fn ($entity) => $this->compactTemplateEntity($entity), (array) data_get($payload, 'entities', $existing?->template_entities ?? [])))),
            'meta'                   => array_merge((array) data_get($existing, 'meta', []), $this->compactTemplateMeta(data_get($input, 'meta', []))),
            'updated_by_uuid'        => session('user'),
            'created_by_uuid'        => $existing?->created_by_uuid ?: session('user'),
        ];
    }

    protected function compactTemplateOrderMeta(array $order, ?RecurringOrderSchedule $existing = null): array
    {
        return [
            'internal_id'           => data_get($order, 'internal_id', data_get($existing?->template_order_meta, 'internal_id')),
            'pod_method'            => data_get($order, 'pod_method', data_get($existing?->template_order_meta, 'pod_method')),
            'pod_required'          => (bool) data_get($order, 'pod_required', data_get($existing?->template_order_meta, 'pod_required', false)),
            'adhoc'                 => (bool) data_get($order, 'adhoc', data_get($existing?->template_order_meta, 'adhoc', false)),
            'adhoc_distance'        => data_get($order, 'adhoc_distance', data_get($existing?->template_order_meta, 'adhoc_distance')),
            'notes'                 => data_get($order, 'notes', data_get($existing?->template_order_meta, 'notes')),
            'type'                  => data_get($order, 'type', data_get($existing?->template_order_meta, 'type')),
            'meta'                  => $this->compactTemplateMeta(data_get($order, 'meta', data_get($existing?->template_order_meta, 'meta', []))),
            'time_window_start'     => data_get($order, 'time_window_start', data_get($existing?->template_order_meta, 'time_window_start')),
            'time_window_end'       => data_get($order, 'time_window_end', data_get($existing?->template_order_meta, 'time_window_end')),
            'required_skills'       => array_values((array) data_get($order, 'required_skills', data_get($existing?->template_order_meta, 'required_skills', []))),
            'orchestrator_priority' => data_get($order, 'orchestrator_priority', data_get($existing?->template_order_meta, 'orchestrator_priority', 50)),
        ];
    }

    protected function compactTemplatePlace($place): ?array
    {
        if (empty($place)) {
            return null;
        }

        return array_filter([
            'uuid'                 => data_get($place, 'uuid') ?: data_get($place, 'id'),
            'public_id'            => data_get($place, 'public_id'),
            'name'                 => data_get($place, 'name'),
            'phone'                => data_get($place, 'phone'),
            'type'                 => data_get($place, 'type', 'place'),
            'address'              => data_get($place, 'address'),
            'street1'              => data_get($place, 'street1'),
            'street2'              => data_get($place, 'street2'),
            'city'                 => data_get($place, 'city'),
            'province'             => data_get($place, 'province'),
            'postal_code'          => data_get($place, 'postal_code'),
            'neighborhood'         => data_get($place, 'neighborhood'),
            'district'             => data_get($place, 'district'),
            'building'             => data_get($place, 'building'),
            'security_access_code' => data_get($place, 'security_access_code'),
            'country'              => data_get($place, 'country'),
            'location'             => data_get($place, 'location'),
            'meta'                 => $this->compactTemplateMeta(data_get($place, 'meta', [])),
        ], fn ($value) => $value !== null && $value !== []);
    }

    protected function compactTemplateWaypoint($waypoint): ?array
    {
        if (empty($waypoint)) {
            return null;
        }

        $place = data_get($waypoint, 'place') ?: $waypoint;

        return array_filter([
            'place'             => $this->compactTemplatePlace($place),
            'type'              => data_get($waypoint, 'type', 'dropoff'),
            'order'             => data_get($waypoint, 'order'),
            'customer_uuid'     => data_get($waypoint, 'customer_uuid'),
            'customer_type'     => data_get($waypoint, 'customer_type'),
            'time_window_start' => data_get($waypoint, 'time_window_start'),
            'time_window_end'   => data_get($waypoint, 'time_window_end'),
            'service_time'      => data_get($waypoint, 'service_time'),
            'notes'             => data_get($waypoint, 'notes'),
            'pod_method'        => data_get($waypoint, 'pod_method'),
            'pod_required'      => (bool) data_get($waypoint, 'pod_required', false),
        ], fn ($value) => $value !== null && $value !== []);
    }

    protected function compactTemplateEntity($entity): ?array
    {
        if (empty($entity)) {
            return null;
        }

        return array_filter([
            'internal_id'      => data_get($entity, 'internal_id'),
            'destination_uuid' => data_get($entity, 'destination_uuid') ?: data_get($entity, 'destination.id') ?: data_get($entity, 'destination.uuid'),
            'name'             => data_get($entity, 'name'),
            'type'             => data_get($entity, 'type', 'entity'),
            'description'      => data_get($entity, 'description'),
            'photo_url'        => data_get($entity, 'photo_url'),
            'currency'         => data_get($entity, 'currency'),
            'weight'           => data_get($entity, 'weight'),
            'weight_unit'      => data_get($entity, 'weight_unit'),
            'length'           => data_get($entity, 'length'),
            'width'            => data_get($entity, 'width'),
            'height'           => data_get($entity, 'height'),
            'dimensions_unit'  => data_get($entity, 'dimensions_unit'),
            'declared_value'   => data_get($entity, 'declared_value'),
            'sku'              => data_get($entity, 'sku'),
            'price'            => data_get($entity, 'price'),
            'sale_price'       => data_get($entity, 'sale_price'),
            'meta'             => $this->compactTemplateMeta(data_get($entity, 'meta', [])),
        ], fn ($value) => $value !== null && $value !== []);
    }

    protected function compactTemplateMeta($meta): array
    {
        $meta = (array) ($meta ?? []);

        unset($meta['_index_resource'], $meta['barcode'], $meta['qr_code'], $meta['tracking'], $meta['trackingNumber']);

        return $meta;
    }
}
