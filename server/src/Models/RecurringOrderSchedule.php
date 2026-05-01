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
}
