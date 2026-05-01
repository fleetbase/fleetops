<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RRule\RRule;

class RecurringOrderSchedule extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use TracksApiCredential;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    protected $table = 'recurring_order_schedules';

    protected $publicIdType = 'recurring_order_schedule';

    protected $payloadKey = 'recurring_order_schedule';

    protected $searchableColumns = ['name', 'description', 'public_id'];

    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'name',
        'description',
        'status',
        'timezone',
        'starts_at',
        'ends_at',
        'rrule',
        'last_materialized_at',
        'materialization_horizon',
        'customer_uuid',
        'customer_type',
        'facilitator_uuid',
        'facilitator_type',
        'order_config_uuid',
        'driver_assigned_uuid',
        'vehicle_assigned_uuid',
        'service_rate_uuid',
        'template_order_meta',
        'template_payload',
        'template_entities',
        'meta',
        'created_by_uuid',
        'updated_by_uuid',
    ];

    protected $casts = [
        'meta' => Json::class,
        'template_order_meta' => Json::class,
        'template_payload' => Json::class,
        'template_entities' => Json::class,
        'customer_type' => PolymorphicType::class,
        'facilitator_type' => PolymorphicType::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_materialized_at' => 'datetime',
        'materialization_horizon' => 'datetime',
    ];

    protected $appends = ['is_active', 'next_occurrence_at'];

    protected $filterParams = ['status', 'customer', 'type', 'scheduled_for', 'created_at', 'updated_at'];

    protected $with = ['customer', 'facilitator', 'orderConfig', 'driverAssigned', 'vehicleAssigned', 'serviceRate'];

    public function customer(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid');
    }

    public function facilitator(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'facilitator_type', 'facilitator_uuid');
    }

    public function orderConfig(): BelongsTo
    {
        return $this->belongsTo(OrderConfig::class, 'order_config_uuid', 'uuid');
    }

    public function driverAssigned(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_assigned_uuid', 'uuid')->withoutGlobalScopes();
    }

    public function vehicleAssigned(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_assigned_uuid', 'uuid')->withoutGlobalScopes();
    }

    public function serviceRate(): BelongsTo
    {
        return $this->belongsTo(ServiceRate::class, 'service_rate_uuid', 'uuid')->withoutGlobalScopes();
    }

    public function generatedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'recurring_order_schedule_uuid', 'uuid')->withoutGlobalScopes();
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringOrderScheduleOccurrence::class, 'recurring_order_schedule_uuid', 'uuid');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeNeedsMaterialization(Builder $query, Carbon $horizon): Builder
    {
        return $query->active()->where(function (Builder $q) use ($horizon) {
            $q->whereNull('materialization_horizon')
                ->orWhere('materialization_horizon', '<', $horizon);
        });
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getNextOccurrenceAtAttribute(): ?Carbon
    {
        return $this->previewOccurrences(now(), now()->copy()->addYear(), 1)->first();
    }

    public function hasRrule(): bool
    {
        return !empty($this->rrule);
    }

    public function pause(): bool
    {
        return (bool) $this->update(['status' => 'paused']);
    }

    public function resume(): bool
    {
        return (bool) $this->update(['status' => 'active']);
    }

    public function cancelSchedule(): bool
    {
        return (bool) $this->update(['status' => 'canceled']);
    }

    public function getRruleInstance(?Carbon $referenceDate = null): ?RRule
    {
        if (!$this->hasRrule()) {
            return null;
        }

        $timezone = $this->timezone ?: 'UTC';
        $referenceDate = $referenceDate ?: ($this->starts_at ? $this->starts_at->copy()->setTimezone($timezone) : now($timezone)->startOfDay());
        $dtStart = ($this->starts_at ? $this->starts_at->copy()->setTimezone($timezone) : $referenceDate->copy())->second(0);
        $rruleValue = preg_replace('/^RRULE:/i', '', trim((string) $this->rrule));

        $dtStartStr = $timezone === 'UTC'
            ? 'DTSTART:' . $dtStart->format('Ymd\THis') . 'Z'
            : 'DTSTART;TZID=' . $timezone . ':' . $dtStart->format('Ymd\THis');

        try {
            return new RRule($dtStartStr . "\n" . 'RRULE:' . $rruleValue);
        } catch (\Throwable $exception) {
            \Log::warning('RecurringOrderSchedule invalid RRULE', [
                'schedule_uuid' => $this->uuid,
                'rrule' => $this->rrule,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function previewOccurrences(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        $rrule = $this->getRruleInstance($from);

        if (!$rrule) {
            return collect();
        }

        $occurrences = collect();

        foreach ($rrule as $occurrence) {
            $carbon = Carbon::instance($occurrence)->setTimezone($this->timezone ?: 'UTC');

            if ($this->starts_at && $carbon->lt($this->starts_at->copy()->setTimezone($this->timezone ?: 'UTC'))) {
                continue;
            }

            if ($this->ends_at && $carbon->gt($this->ends_at->copy()->setTimezone($this->timezone ?: 'UTC'))) {
                break;
            }

            if ($carbon->gt($to)) {
                break;
            }

            if ($carbon->gte($from)) {
                $occurrences->push($carbon->copy());
            }

            if ($occurrences->count() >= $limit) {
                break;
            }
        }

        return $occurrences;
    }

    public function getUpcomingOccurrences(int $limit = 25): array
    {
        $timezone = $this->timezone ?: 'UTC';
        $preview = $this->previewOccurrences(now($timezone), now($timezone)->addYears(1), $limit);
        $states = $this->occurrences()
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

    public function getApiPayloadFromRequest(Request $request): array
    {
        $input = parent::getApiPayloadFromRequest($request);

        return $this->normalizeApiPayload($input);
    }

    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        $builder = $this->where(function ($q) use ($id) {
            $publicIdColumn = $this->getQualifiedPublicId();

            $q->where($this->getQualifiedKeyName(), $id);
            if ($this->isColumn($publicIdColumn)) {
                $q->orWhere($publicIdColumn, $id);
            }
        });

        $companyUuid = session('company');
        if ($companyUuid && $this->isColumn($this->qualifyColumn('company_uuid'))) {
            $builder->where($this->qualifyColumn('company_uuid'), $companyUuid);
        }

        $builder = $this->applyDirectivesToQuery($request, $builder);
        $record = $builder->first();

        if (!$record) {
            throw new \Exception($this->getApiHumanReadableName() . ' not found');
        }

        $input = parent::getApiPayloadFromRequest($request);
        $input = $this->normalizeApiPayload($input, $record);
        $input = $this->fillSessionAttributes($input, [], ['updated_by_uuid']);

        if (is_callable($onBefore)) {
            $before = $onBefore($request, $record, $input);
            if ($before instanceof \Illuminate\Http\JsonResponse) {
                return $before;
            }
        }

        $keys = array_keys($input);

        foreach ($keys as $key) {
            if ($this->isInvalidUpdateParam($key)) {
                throw new \Exception('Invalid param "' . $key . '" in update request!');
            }
        }

        $input = \Illuminate\Support\Arr::except($input, ['uuid', 'public_id', 'deleted_at', 'updated_at', 'created_at']);
        try {
            $record->update($input);
        } catch (\Exception $e) {
            throw new \Exception(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Failed to update ' . $this->getApiHumanReadableName());
        }

        if (isset($options['return_object']) && $options['return_object'] === true) {
            return $record;
        }

        if (is_callable($onAfter)) {
            $after = $onAfter($request, $record, $input);
            if ($after instanceof \Illuminate\Http\JsonResponse) {
                return $after;
            }
        }

        $record->refresh();

        $with = $request->or(['with', 'expand'], []);
        if (!empty($with)) {
            $record->load($with);
        }

        $withCount = $request->array('with_count', []);
        if (!empty($withCount)) {
            $record->loadCount($withCount);
        }

        return static::mutateModelWithRequest($request, $record);
    }

    protected function normalizeApiPayload(array $input, ?self $existing = null): array
    {
        $order = (array) ($input['order'] ?? []);
        $payload = (array) data_get($order, 'payload', []);

        if (!$existing && empty($order)) {
            throw new \Exception('Recurring order schedule requires an order payload.');
        }

        return [
            'name' => data_get($input, 'name', $existing?->name),
            'description' => data_get($input, 'description', $existing?->description),
            'status' => data_get($input, 'status', $existing?->status ?? 'active'),
            'timezone' => data_get($input, 'timezone', $existing?->timezone ?? 'UTC'),
            'starts_at' => !empty($input['starts_at']) ? Carbon::parse($input['starts_at']) : $existing?->starts_at,
            'ends_at' => !empty($input['ends_at']) ? Carbon::parse($input['ends_at']) : $existing?->ends_at,
            'rrule' => data_get($input, 'rrule', $existing?->rrule),
            'company_uuid' => session('company', $existing?->company_uuid),
            'customer_uuid' => data_get($order, 'customer_uuid') ?: data_get($order, 'customer.id') ?: $existing?->customer_uuid,
            'customer_type' => data_get($order, 'customer_type', $existing?->customer_type),
            'facilitator_uuid' => data_get($order, 'facilitator_uuid') ?: data_get($order, 'facilitator.id') ?: $existing?->facilitator_uuid,
            'facilitator_type' => data_get($order, 'facilitator_type', $existing?->facilitator_type),
            'order_config_uuid' => data_get($order, 'order_config_uuid') ?: data_get($order, 'order_config.id') ?: $existing?->order_config_uuid,
            'driver_assigned_uuid' => data_get($order, 'driver_assigned_uuid') ?: data_get($order, 'driver_assigned.id') ?: $existing?->driver_assigned_uuid,
            'vehicle_assigned_uuid' => data_get($order, 'vehicle_assigned_uuid') ?: data_get($order, 'vehicle_assigned.id') ?: $existing?->vehicle_assigned_uuid,
            'service_rate_uuid' => data_get($input, 'service_rate_uuid') ?: data_get($order, 'service_rate_uuid') ?: $existing?->service_rate_uuid,
            'template_order_meta' => [
                'internal_id' => data_get($order, 'internal_id', data_get($existing?->template_order_meta, 'internal_id')),
                'pod_method' => data_get($order, 'pod_method', data_get($existing?->template_order_meta, 'pod_method')),
                'pod_required' => (bool) data_get($order, 'pod_required', data_get($existing?->template_order_meta, 'pod_required', false)),
                'adhoc' => (bool) data_get($order, 'adhoc', data_get($existing?->template_order_meta, 'adhoc', false)),
                'adhoc_distance' => data_get($order, 'adhoc_distance', data_get($existing?->template_order_meta, 'adhoc_distance')),
                'notes' => data_get($order, 'notes', data_get($existing?->template_order_meta, 'notes')),
                'type' => data_get($order, 'type', data_get($existing?->template_order_meta, 'type')),
                'meta' => data_get($order, 'meta', data_get($existing?->template_order_meta, 'meta', [])),
                'time_window_start' => data_get($order, 'time_window_start', data_get($existing?->template_order_meta, 'time_window_start')),
                'time_window_end' => data_get($order, 'time_window_end', data_get($existing?->template_order_meta, 'time_window_end')),
                'required_skills' => data_get($order, 'required_skills', data_get($existing?->template_order_meta, 'required_skills', [])),
                'orchestrator_priority' => data_get($order, 'orchestrator_priority', data_get($existing?->template_order_meta, 'orchestrator_priority', 50)),
            ],
            'template_payload' => [
                'pickup' => data_get($payload, 'pickup', data_get($existing?->template_payload, 'pickup')),
                'dropoff' => data_get($payload, 'dropoff', data_get($existing?->template_payload, 'dropoff')),
                'return' => data_get($payload, 'return', data_get($existing?->template_payload, 'return')),
                'waypoints' => array_values((array) data_get($payload, 'waypoints', data_get($existing?->template_payload, 'waypoints', []))),
                'type' => data_get($payload, 'type', data_get($existing?->template_payload, 'type')),
                'payment_method' => data_get($payload, 'payment_method', data_get($existing?->template_payload, 'payment_method')),
                'cod_amount' => data_get($payload, 'cod_amount', data_get($existing?->template_payload, 'cod_amount')),
                'cod_currency' => data_get($payload, 'cod_currency', data_get($existing?->template_payload, 'cod_currency')),
                'cod_payment_method' => data_get($payload, 'cod_payment_method', data_get($existing?->template_payload, 'cod_payment_method')),
                'meta' => data_get($payload, 'meta', data_get($existing?->template_payload, 'meta', [])),
            ],
            'template_entities' => array_values((array) data_get($payload, 'entities', $existing?->template_entities ?? [])),
            'meta' => array_merge((array) data_get($existing, 'meta', []), (array) data_get($input, 'meta', [])),
            'updated_by_uuid' => session('user'),
            'created_by_uuid' => $existing?->created_by_uuid ?: session('user'),
        ];
    }
}
