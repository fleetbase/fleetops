<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\Models\Category;
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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Class Asset.
 *
 * Represents a physical asset in the fleet (truck, trailer, container, drone, forklift, etc.).
 * Assets are the primary operational units that can be tracked, maintained, and managed.
 */
class Asset extends Model
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
    protected $table = 'assets';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'asset';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'code', 'vin', 'plate_number', 'make', 'model', 'serial_number', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['category_uuid', 'vendor_uuid', 'type', 'status', 'make', 'model', 'year'];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'category_uuid',
        'vendor_uuid',
        'warranty_uuid',
        'telematic_uuid',
        'assigned_to_uuid',
        'assigned_to_type',
        'operator_uuid',
        'operator_type',
        'current_place_uuid',
        'photo_uuid',
        'name',
        'description',
        'code',
        'type',
        'location',
        'speed',
        'heading',
        'altitude',
        'status',
        'usage_type',
        'vin',
        'plate_number',
        'make',
        'model',
        'year',
        'color',
        'serial_number',
        'measurement_system',
        'odometer',
        'odometer_unit',
        'transmission',
        'fuel_volume_unit',
        'fuel_Type',
        'ownership_type',
        'engine_hours',
        'gvw',
        'capacity',
        'specs',
        'attributes',
        'notes',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'category_name',
        'vendor_name',
        'warranty_name',
        'current_location',
        'photo_url',
        'display_name',
        'is_online',
        'last_maintenance',
        'next_maintenance_due',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['category', 'vendor', 'warranty', 'telematic', 'currentPlace', 'photo'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'year'             => 'integer',
        'odometer'         => 'integer',
        'engine_hours'     => 'integer',
        'gvw'              => 'decimal:2',
        'capacity'         => Json::class,
        'specs'            => Json::class,
        'attributes'       => Json::class,
        'location'         => Point::class,
        'assigned_to_type' => PolymorphicType::class,
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
    protected static $logName = 'asset';

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

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function operator(): MorphTo
    {
        return $this->morphTo();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_uuid', 'uuid');
    }

    public function telematic(): BelongsTo
    {
        return $this->belongsTo(Telematic::class, 'telematic_uuid', 'uuid');
    }

    public function currentPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'current_place_uuid', 'uuid');
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

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'attachable_uuid');
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class, 'equipable_uuid', 'uuid')
            ->where('equipable_type', static::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'maintainable_uuid', 'uuid')
            ->where('maintainable_type', static::class);
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class, 'sensorable_uuid', 'uuid')
            ->where('sensorable_type', static::class);
    }

    public function parts(): MorphMany
    {
        return $this->morphMany(Part::class, 'asset');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'subject_uuid');
    }

    /**
     * Get the category name.
     */
    public function getCategoryNameAttribute(): ?string
    {
        return $this->category?->name;
    }

    /**
     * Get the vendor name.
     */
    public function getVendorNameAttribute(): ?string
    {
        return $this->vendor?->name;
    }

    /**
     * Get the warranty name.
     */
    public function getWarrantyNameAttribute(): ?string
    {
        return $this->warranty?->name;
    }

    /**
     * Get the current location information.
     */
    public function getCurrentLocationAttribute(): ?array
    {
        if ($this->currentPlace) {
            return [
                'name'      => $this->currentPlace->name,
                'address'   => $this->currentPlace->address,
                'latitude'  => $this->currentPlace->latitude,
                'longitude' => $this->currentPlace->longitude,
            ];
        }

        // Try to get location from telematic
        if ($this->telematic && $this->telematic->last_location) {
            return $this->telematic->last_location;
        }

        return null;
    }

    /**
     * Get the photo URL.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo?->url;
    }

    /**
     * Get the display name for the asset.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->name) {
            return $this->name;
        }

        $parts = array_filter([
            $this->make,
            $this->model,
            $this->year,
            $this->code,
        ]);

        return implode(' ', $parts) ?: 'Asset #' . $this->public_id;
    }

    /**
     * Check if the asset is currently online (via telematic).
     */
    public function getIsOnlineAttribute(): bool
    {
        return $this->telematic?->is_online ?? false;
    }

    /**
     * Get the last maintenance record.
     */
    public function getLastMaintenanceAttribute(): ?Maintenance
    {
        return $this->maintenances()
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->first();
    }

    /**
     * Get the next maintenance due date.
     */
    public function getNextMaintenanceDueAttribute(): ?string
    {
        $nextMaintenance = $this->maintenances()
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at', 'asc')
            ->first();

        return $nextMaintenance?->scheduled_at?->toDateString();
    }

    /**
     * Scope to get assets by type.
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
     * Scope to get active assets.
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
     * Scope to get assets with telematics.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithTelematics($query)
    {
        return $query->whereNotNull('telematic_uuid');
    }

    /**
     * Scope to get assets that are online.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnline($query)
    {
        return $query->whereHas('telematic', function ($q) {
            $q->online();
        });
    }

    /**
     * Update the asset's odometer reading.
     */
    public function updateOdometer(int $reading, ?string $source = null): bool
    {
        if ($reading > $this->odometer) {
            $updated = $this->update(['odometer' => $reading]);

            if ($updated) {
                activity('odometer_update')
                    ->performedOn($this)
                    ->withProperties([
                        'old_reading' => $this->getOriginal('odometer'),
                        'new_reading' => $reading,
                        'source'      => $source ?? 'manual',
                    ])
                    ->log('Odometer updated');
            }

            return $updated;
        }

        return false;
    }

    /**
     * Update the asset's engine hours.
     */
    public function updateEngineHours(int $hours, ?string $source = null): bool
    {
        if ($hours > $this->engine_hours) {
            $updated = $this->update(['engine_hours' => $hours]);

            if ($updated) {
                activity('engine_hours_update')
                    ->performedOn($this)
                    ->withProperties([
                        'old_hours' => $this->getOriginal('engine_hours'),
                        'new_hours' => $hours,
                        'source'    => $source ?? 'manual',
                    ])
                    ->log('Engine hours updated');
            }

            return $updated;
        }

        return false;
    }

    /**
     * Check if the asset needs maintenance.
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

        // Check maintenance intervals based on odometer or engine hours
        $specs               = $this->specs ?? [];
        $maintenanceInterval = $specs['maintenance_interval'] ?? null;

        if ($maintenanceInterval && $this->last_maintenance) {
            $milesSinceLastMaintenance = $this->odometer - $this->last_maintenance->odometer;

            return $milesSinceLastMaintenance >= $maintenanceInterval;
        }

        return false;
    }

    /**
     * Get the asset's utilization rate.
     */
    public function getUtilizationRate(int $days = 30): float
    {
        // This would calculate based on actual usage data
        // For now, return a placeholder calculation
        $totalHours = $days * 24;
        $usedHours  = $this->engine_hours ?? 0;

        return min(($usedHours / $totalHours) * 100, 100);
    }

    /**
     * Schedule maintenance for the asset.
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
            'odometer'          => $this->odometer,
            'engine_hours'      => $this->engine_hours,
            'summary'           => $details['summary'] ?? null,
            'notes'             => $details['notes'] ?? null,
            'created_by_uuid'   => auth()->id(),
        ]);
    }
}
