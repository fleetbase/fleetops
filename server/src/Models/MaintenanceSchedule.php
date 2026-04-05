<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasCustomFields;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class MaintenanceSchedule.
 *
 * Defines a recurring preventive maintenance rule for an asset. When a schedule's
 * next-due thresholds are reached (by date, odometer, or engine hours), the
 * ProcessMaintenanceTriggers command automatically creates a WorkOrder from this
 * schedule's default settings.
 */
class MaintenanceSchedule extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use HasCustomFields;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'maintenance_schedules';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'schedule';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'type', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['status', 'type', 'interval_method', 'interval_type', 'subject_type', 'subject_uuid'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'subject_type',
        'subject_uuid',
        'name',
        'type',
        'status',
        'interval_method',
        'interval_type',
        'interval_value',
        'interval_unit',
        'interval_distance',
        'interval_engine_hours',
        'last_service_odometer',
        'last_service_engine_hours',
        'last_service_date',
        'next_due_date',
        'next_due_odometer',
        'next_due_engine_hours',
        'default_priority',
        'default_assignee_type',
        'default_assignee_uuid',
        'instructions',
        'reminder_offsets',
        'meta',
        'created_by_uuid',
        'updated_by_uuid',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'meta'                   => 'array',
        'reminder_offsets'       => 'array',
        'next_due_date'          => 'datetime',
        'last_service_date'      => 'datetime',
        'subject_type'           => PolymorphicType::class,
        'default_assignee_type'  => PolymorphicType::class,
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * Relationships to always eager load.
     *
     * @var array
     */
    protected $with = ['subject', 'defaultAssignee'];

    /**
     * Activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'type', 'interval_value', 'interval_unit', 'next_due_date'])
            ->logOnlyDirty();
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The asset this schedule applies to.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * The default assignee for auto-generated work orders.
     */
    public function defaultAssignee(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'default_assignee_type', 'default_assignee_uuid');
    }

    /**
     * Work orders generated from this schedule.
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'schedule_uuid', 'uuid');
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether this schedule is currently due based on the given
     * asset readings.
     *
     * @param int|null            $currentOdometer    Current odometer reading in base unit
     * @param int|null            $currentEngineHours Current engine hours
     * @param \Carbon\Carbon|null $asOf               Date to check against (defaults to now)
     */
    public function isDue(?int $currentOdometer = null, ?int $currentEngineHours = null, ?\Carbon\Carbon $asOf = null): bool
    {
        $asOf = $asOf ?? now();

        if ($this->status !== 'active') {
            return false;
        }

        // Date trigger
        if ($this->next_due_date && $asOf->gte($this->next_due_date)) {
            return true;
        }

        // Odometer trigger
        if ($this->next_due_odometer && $currentOdometer !== null && $currentOdometer >= $this->next_due_odometer) {
            return true;
        }

        // Engine hours trigger
        if ($this->next_due_engine_hours && $currentEngineHours !== null && $currentEngineHours >= $this->next_due_engine_hours) {
            return true;
        }

        return false;
    }

    /**
     * Reset the schedule's next-due thresholds after a work order has been completed.
     * Called by the WorkOrderObserver when a work order linked to this schedule is closed.
     */
    public function resetAfterCompletion(
        ?int $completedOdometer = null,
        ?int $completedEngineHours = null,
        ?\Carbon\Carbon $completedAt = null,
    ): bool {
        $completedAt = $completedAt ?? now();

        $update = [
            'last_service_date'         => $completedAt,
            'last_service_odometer'     => $completedOdometer ?? $this->last_service_odometer,
            'last_service_engine_hours' => $completedEngineHours ?? $this->last_service_engine_hours,
        ];

        // Recompute next_due_date
        if ($this->interval_value && $this->interval_unit) {
            $unit                    = $this->interval_unit; // days, months, weeks, years
            $update['next_due_date'] = $completedAt->copy()->add($this->interval_value . ' ' . $unit);
        }

        // Recompute next_due_odometer
        if ($this->interval_distance && $completedOdometer !== null) {
            $update['next_due_odometer'] = $completedOdometer + $this->interval_distance;
        }

        // Recompute next_due_engine_hours
        if ($this->interval_engine_hours && $completedEngineHours !== null) {
            $update['next_due_engine_hours'] = $completedEngineHours + $this->interval_engine_hours;
        }

        return $this->update($update);
    }

    /**
     * Pause the schedule.
     */
    public function pause(): bool
    {
        return $this->update(['status' => 'paused']);
    }

    /**
     * Resume a paused schedule.
     */
    public function resume(): bool
    {
        return $this->update(['status' => 'active']);
    }

    /**
     * Mark the schedule as completed (one-time schedules).
     */
    public function complete(): bool
    {
        return $this->update(['status' => 'completed']);
    }

    /**
     * Create a MaintenanceSchedule instance from an import row.
     *
     * @param array $row          Associative array from the import spreadsheet
     * @param bool  $saveInstance Whether to persist the record immediately
     */
    public static function createFromImport(array $row, bool $saveInstance = false): MaintenanceSchedule
    {
        $row = array_filter($row);

        $name                   = Utils::or($row, ['name', 'schedule_name']);
        $type                   = Utils::or($row, ['type', 'schedule_type'], 'preventive');
        $status                 = Utils::or($row, ['status'], 'active');
        $intervalMethod         = Utils::or($row, ['interval_method'], 'time');
        $intervalType           = Utils::or($row, ['interval_type'], 'recurring');
        $intervalValue          = Utils::or($row, ['interval_value']);
        $intervalUnit           = Utils::or($row, ['interval_unit'], 'days');
        $intervalDistance       = Utils::or($row, ['interval_distance']);
        $intervalEngineHours    = Utils::or($row, ['interval_engine_hours']);
        $lastServiceOdometer    = Utils::or($row, ['last_service_odometer']);
        $lastServiceEngineHours = Utils::or($row, ['last_service_engine_hours']);
        $lastServiceDate        = Utils::or($row, ['last_service_date']);
        $nextDueDate            = Utils::or($row, ['next_due_date']);
        $nextDueOdometer        = Utils::or($row, ['next_due_odometer']);
        $nextDueEngineHours     = Utils::or($row, ['next_due_engine_hours']);
        $defaultPriority        = Utils::or($row, ['default_priority'], 'normal');
        $instructions           = Utils::or($row, ['instructions', 'description']);
        $vehicleName            = Utils::or($row, ['vehicle', 'vehicle_name', 'asset', 'subject']);
        $vendorName             = Utils::or($row, ['vendor', 'vendor_name', 'default_assignee']);

        $schedule = new static([
            'company_uuid'              => session('company'),
            'name'                      => $name,
            'type'                      => $type,
            'status'                    => $status,
            'interval_method'           => $intervalMethod,
            'interval_type'             => $intervalType,
            'interval_value'            => $intervalValue ? (int) $intervalValue : null,
            'interval_unit'             => $intervalUnit,
            'interval_distance'         => $intervalDistance ? (float) $intervalDistance : null,
            'interval_engine_hours'     => $intervalEngineHours ? (float) $intervalEngineHours : null,
            'last_service_odometer'     => $lastServiceOdometer ? (float) $lastServiceOdometer : null,
            'last_service_engine_hours' => $lastServiceEngineHours ? (float) $lastServiceEngineHours : null,
            'last_service_date'         => $lastServiceDate ? \Carbon\Carbon::parse($lastServiceDate) : null,
            'next_due_date'             => $nextDueDate ? \Carbon\Carbon::parse($nextDueDate) : null,
            'next_due_odometer'         => $nextDueOdometer ? (float) $nextDueOdometer : null,
            'next_due_engine_hours'     => $nextDueEngineHours ? (float) $nextDueEngineHours : null,
            'default_priority'          => $defaultPriority,
            'instructions'              => $instructions,
        ]);

        // Attempt to resolve the subject (vehicle or equipment) by identifier
        if ($vehicleName) {
            $vehicle = Vehicle::findByName($vehicleName);
            if ($vehicle) {
                $schedule->subject_type = Vehicle::class;
                $schedule->subject_uuid = $vehicle->uuid;
            } else {
                $equipment = Equipment::where('company_uuid', session('company'))
                    ->where(function ($q) use ($vehicleName) {
                        $q->where('name', 'like', '%' . $vehicleName . '%')
                          ->orWhere('public_id', $vehicleName)
                          ->orWhere('serial_number', $vehicleName);
                    })->first();
                if ($equipment) {
                    $schedule->subject_type = Equipment::class;
                    $schedule->subject_uuid = $equipment->uuid;
                }
            }
        }

        // Attempt to resolve default assignee (vendor) by name
        if ($vendorName) {
            $vendor = Vendor::where('company_uuid', session('company'))
                ->where('name', 'like', '%' . $vendorName . '%')
                ->first();
            if ($vendor) {
                $schedule->default_assignee_type = Vendor::class;
                $schedule->default_assignee_uuid = $vendor->uuid;
            }
        }

        if ($saveInstance === true) {
            $schedule->save();
        }

        return $schedule;
    }
}
