<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\File;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasCustomFields;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Class Equipment.
 *
 * Represents auxiliary gear that can be assigned to assets or other entities.
 * Examples include PPE, refrigeration units, tools, liftgates, and other equipment.
 */
class Equipment extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasSlug;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use HasCustomFields;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'equipments';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'equipment';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'code', 'type', 'serial_number', 'manufacturer', 'model', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['type', 'status', 'manufacturer', 'warranty_uuid', 'equipable_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'warranty_uuid',
        'photo_uuid',
        'name',
        'code',
        'type',
        'status',
        'serial_number',
        'manufacturer',
        'model',
        'equipable_type',
        'equipable_uuid',
        'purchased_at',
        'purchase_price',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'warranty_name',
        'photo_url',
        'equipped_to_name',
        'is_equipped',
        'age_in_days',
        'depreciated_value',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['warranty', 'photo', 'equipable'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'purchased_at'   => 'date',
        'purchase_price' => 'decimal:2',
        'meta'           => Json::class,
    ];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'equipment';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name', 'code'])
            ->saveSlugsTo('slug');
    }

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_uuid', 'uuid');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(File::class, 'photo_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function equipable(): MorphTo
    {
        return $this->morphTo();
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'maintainable_uuid', 'uuid')
            ->where('maintainable_type', static::class);
    }

    /**
     * Get the warranty name.
     */
    public function getWarrantyNameAttribute(): ?string
    {
        return $this->warranty?->name;
    }

    /**
     * Get the photo URL.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo?->url;
    }

    /**
     * Get the name of what the equipment is equipped to.
     */
    public function getEquippedToNameAttribute(): ?string
    {
        if ($this->equipable) {
            return $this->equipable->name ?? $this->equipable->display_name ?? null;
        }

        return null;
    }

    /**
     * Check if the equipment is currently equipped to something.
     */
    public function getIsEquippedAttribute(): bool
    {
        return !is_null($this->equipable_uuid) && !is_null($this->equipable_type);
    }

    /**
     * Get the age of the equipment in days.
     */
    public function getAgeInDaysAttribute(): ?int
    {
        if ($this->purchased_at) {
            return $this->purchased_at->diffInDays(now());
        }

        return null;
    }

    /**
     * Get the depreciated value of the equipment.
     */
    public function getDepreciatedValueAttribute(): ?float
    {
        if (!$this->purchase_price || !$this->purchased_at) {
            return null;
        }

        $meta             = $this->meta ?? [];
        $depreciationRate = $meta['depreciation_rate'] ?? 0.1; // 10% per year default
        $yearsOld         = $this->purchased_at->diffInYears(now());

        $depreciatedAmount = $this->purchase_price * ($depreciationRate * $yearsOld);
        $currentValue      = $this->purchase_price - $depreciatedAmount;

        return max($currentValue, 0); // Don't go below 0
    }

    /**
     * Scope to get equipment by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get active equipment.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get equipped equipment.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEquipped($query)
    {
        return $query->whereNotNull('equipable_uuid')
                    ->whereNotNull('equipable_type');
    }

    /**
     * Scope to get unequipped equipment.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnequipped($query)
    {
        return $query->whereNull('equipable_uuid')
                    ->orWhereNull('equipable_type');
    }

    /**
     * Scope to get equipment by manufacturer.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByManufacturer($query, string $manufacturer)
    {
        return $query->where('manufacturer', $manufacturer);
    }

    /**
     * Equip this equipment to an entity.
     */
    public function equipTo(Model $equipable): bool
    {
        $updated = $this->update([
            'equipable_type' => get_class($equipable),
            'equipable_uuid' => $equipable->uuid,
        ]);

        if ($updated) {
            activity('equipment_equipped')
                ->performedOn($this)
                ->withProperties([
                    'equipped_to_type' => get_class($equipable),
                    'equipped_to_uuid' => $equipable->uuid,
                    'equipped_to_name' => $equipable->name ?? $equipable->display_name ?? null,
                ])
                ->log('Equipment equipped');
        }

        return $updated;
    }

    /**
     * Unequip this equipment.
     */
    public function unequip(): bool
    {
        $oldEquipableType = $this->equipable_type;
        $oldEquipableUuid = $this->equipable_uuid;

        $updated = $this->update([
            'equipable_type' => null,
            'equipable_uuid' => null,
        ]);

        if ($updated) {
            activity('equipment_unequipped')
                ->performedOn($this)
                ->withProperties([
                    'previous_equipped_to_type' => $oldEquipableType,
                    'previous_equipped_to_uuid' => $oldEquipableUuid,
                ])
                ->log('Equipment unequipped');
        }

        return $updated;
    }

    /**
     * Check if the equipment needs maintenance.
     */
    public function needsMaintenance(): bool
    {
        // Check if there's overdue maintenance
        $overdueMaintenance = $this->maintenances()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<', now())
            ->exists();

        if ($overdueMaintenance) {
            return true;
        }

        // Check maintenance intervals based on age or usage
        $meta                    = $this->meta ?? [];
        $maintenanceIntervalDays = $meta['maintenance_interval_days'] ?? null;

        if ($maintenanceIntervalDays && $this->purchased_at) {
            $lastMaintenance = $this->maintenances()
                ->where('status', 'completed')
                ->orderBy('completed_at', 'desc')
                ->first();

            $lastMaintenanceDate      = $lastMaintenance?->completed_at ?? $this->purchased_at;
            $daysSinceLastMaintenance = $lastMaintenanceDate->diffInDays(now());

            return $daysSinceLastMaintenance >= $maintenanceIntervalDays;
        }

        return false;
    }

    /**
     * Schedule maintenance for the equipment.
     */
    public function scheduleMaintenance(string $type, \DateTime $scheduledAt, array $details = []): Maintenance
    {
        return Maintenance::create([
            'company_uuid'      => $this->company_uuid,
            'maintainable_type' => static::class,
            'maintainable_uuid' => $this->uuid,
            'type'              => $type,
            'status'            => 'scheduled',
            'scheduled_at'      => $scheduledAt,
            'summary'           => $details['summary'] ?? null,
            'notes'             => $details['notes'] ?? null,
            'created_by_uuid'   => auth()->id(),
        ]);
    }

    /**
     * Get the utilization rate of the equipment.
     */
    public function getUtilizationRate(int $days = 30): float
    {
        // This would calculate based on actual usage data
        // For now, return a placeholder calculation based on equipped status
        if ($this->is_equipped) {
            return 75.0; // Assume 75% utilization when equipped
        }

        return 0.0;
    }

    /**
     * Get the maintenance cost for a period.
     */
    public function getMaintenanceCost(int $days = 365): float
    {
        $startDate = now()->subDays($days);

        return $this->maintenances()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $startDate)
            ->sum('total_cost') ?? 0;
    }

    /**
     * Check if the equipment is under warranty.
     */
    public function isUnderWarranty(): bool
    {
        return $this->warranty && $this->warranty->is_active;
    }

    /**
     * Get the replacement cost estimate.
     */
    public function getReplacementCostEstimate(): ?float
    {
        $meta = $this->meta ?? [];

        if (isset($meta['replacement_cost'])) {
            return $meta['replacement_cost'];
        }

        // Estimate based on purchase price with inflation
        if ($this->purchase_price && $this->purchased_at) {
            $yearsOld      = $this->purchased_at->diffInYears(now());
            $inflationRate = $meta['inflation_rate'] ?? 0.03; // 3% default

            return $this->purchase_price * pow(1 + $inflationRate, $yearsOld);
        }

        return null;
    }
}
