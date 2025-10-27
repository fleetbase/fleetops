<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Fleetbase\Models\Alert;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Class Sensor.
 *
 * Represents a sensor that can measure and report various metrics like temperature,
 * door status, fuel level, tire pressure, etc. Sensors can be attached to devices,
 * assets, or other entities in the fleet management system.
 */
class Sensor extends Model
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
    use SpatialTrait;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'sensors';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'sensor';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'sensor_type', 'unit', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['sensor_type', 'status', 'device_uuid', 'warranty_uuid', 'sensorable_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'device_uuid',
        'warranty_uuid',
        'telematic_uuid',
        'photo_uuid',
        'name',
        'type',
        'internal_id',
        'imei',
        'imsi',
        'firmware_version',
        'serial_number',
        'last_position',
        'unit',
        'min_threshold',
        'max_threshold',
        'threshold_inclusive',
        'last_reading_at',
        'last_value',
        'calibration',
        'report_frequency_sec',
        'sensorable_type',
        'sensorable_uuid',
        'status',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'is_active',
        'threshold_status',
        'photo_url',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['last_position'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'min_threshold'               => 'float',
        'max_threshold'               => 'float',
        'threshold_inclusive'         => 'boolean',
        'last_reading_at'             => 'datetime',
        'report_frequency_sec'        => 'integer',
        'last_position'               => Point::class,
        'calibration'                 => Json::class,
        'meta'                        => Json::class,
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
    protected static $logName = 'sensor';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name', 'sensor_type'])
            ->saveSlugsTo('slug');
    }

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function telematic(): BelongsTo
    {
        return $this->belongsTo(Telematic::class, 'telematic_uuid', 'uuid');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_uuid', 'uuid');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function sensorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'subject_uuid', 'uuid')
            ->where('subject_type', static::class);
    }

    /**
     * Get photo URL attribute.
     *
     * @return string
     */
    public function getPhotoUrlAttribute()
    {
        return data_get($this, 'photo.url', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png');
    }

    /**
     * Get the device name.
     */
    public function getDeviceNameAttribute(): ?string
    {
        return $this->device?->name;
    }

    /**
     * Get the warranty name.
     */
    public function getWarrantyNameAttribute(): ?string
    {
        return $this->warranty?->name;
    }

    /**
     * Get the name of what the sensor is attached to.
     */
    public function getAttachedToNameAttribute(): ?string
    {
        if ($this->sensorable) {
            return $this->sensorable->name ?? $this->sensorable->display_name ?? null;
        }

        return null;
    }

    /**
     * Check if the sensor is currently active.
     */
    public function getIsActiveAttribute(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Consider active if we've received a reading within the expected frequency
        if ($this->last_reading_at && $this->report_frequency_sec) {
            $expectedNextReading = $this->last_reading_at->addSeconds($this->report_frequency_sec * 2); // Allow 2x frequency

            return now()->lte($expectedNextReading);
        }

        return $this->status === 'active';
    }

    /**
     * Get the threshold status of the last reading.
     */
    public function getThresholdStatusAttribute(): string
    {
        if (!$this->last_value || (!$this->min_threshold && !$this->max_threshold)) {
            return 'normal';
        }

        $value = (float) $this->last_value;

        if ($this->min_threshold && $this->max_threshold) {
            if ($this->threshold_inclusive) {
                if ($value < $this->min_threshold || $value > $this->max_threshold) {
                    return 'out_of_range';
                }
            } else {
                if ($value <= $this->min_threshold || $value >= $this->max_threshold) {
                    return 'out_of_range';
                }
            }
        } elseif ($this->min_threshold) {
            if ($this->threshold_inclusive ? $value < $this->min_threshold : $value <= $this->min_threshold) {
                return 'below_minimum';
            }
        } elseif ($this->max_threshold) {
            if ($this->threshold_inclusive ? $value > $this->max_threshold : $value >= $this->max_threshold) {
                return 'above_maximum';
            }
        }

        return 'normal';
    }

    /**
     * Get the last reading with unit formatting.
     */
    public function getLastReadingFormattedAttribute(): ?string
    {
        if (!$this->last_value) {
            return null;
        }

        return $this->last_value . ($this->unit ? ' ' . $this->unit : '');
    }

    /**
     * Scope to get sensors by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('sensor_type', $type);
    }

    /**
     * Scope to get active sensors.
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
     * Scope to get sensors with recent readings.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithRecentReadings($query, int $minutes = 60)
    {
        return $query->where('last_reading_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Scope to get sensors that are out of threshold.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOutOfThreshold($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw('CAST(last_value AS DECIMAL) < min_threshold')
              ->orWhereRaw('CAST(last_value AS DECIMAL) > max_threshold');
        })->whereNotNull('last_value');
    }

    /**
     * Record a new sensor reading.
     */
    public function recordReading($value, ?\DateTime $timestamp = null): bool
    {
        $timestamp = $timestamp ?? now();

        $updated = $this->update([
            'last_value'      => $value,
            'last_reading_at' => $timestamp,
        ]);

        if ($updated) {
            // Check if the reading is out of threshold and create alert if needed
            $this->checkThresholdAndCreateAlert($value);

            activity('sensor_reading')
                ->performedOn($this)
                ->withProperties([
                    'value'            => $value,
                    'unit'             => $this->unit,
                    'threshold_status' => $this->threshold_status,
                    'timestamp'        => $timestamp,
                ])
                ->log('Sensor reading recorded');
        }

        return $updated;
    }

    /**
     * Check threshold and create alert if needed.
     */
    protected function checkThresholdAndCreateAlert($value): void
    {
        $thresholdStatus = $this->threshold_status;

        if ($thresholdStatus !== 'normal') {
            // Check if there's already an open alert for this sensor
            $existingAlert = $this->alerts()
                ->where('status', 'open')
                ->where('type', 'sensor_threshold')
                ->first();

            if (!$existingAlert) {
                Alert::create([
                    'company_uuid' => $this->company_uuid,
                    'type'         => 'sensor_threshold',
                    'severity'     => $this->getSeverityForThresholdStatus($thresholdStatus),
                    'status'       => 'open',
                    'subject_type' => static::class,
                    'subject_uuid' => $this->uuid,
                    'message'      => $this->generateThresholdAlertMessage($value, $thresholdStatus),
                    'context'      => [
                        'sensor_name'      => $this->name,
                        'sensor_type'      => $this->sensor_type,
                        'value'            => $value,
                        'unit'             => $this->unit,
                        'threshold_status' => $thresholdStatus,
                        'min_threshold'    => $this->min_threshold,
                        'max_threshold'    => $this->max_threshold,
                    ],
                    'triggered_at' => now(),
                ]);
            }
        } else {
            // Resolve any open threshold alerts for this sensor
            $this->alerts()
                ->where('status', 'open')
                ->where('type', 'sensor_threshold')
                ->update([
                    'status'      => 'resolved',
                    'resolved_at' => now(),
                ]);
        }
    }

    /**
     * Get severity level for threshold status.
     */
    protected function getSeverityForThresholdStatus(string $thresholdStatus): string
    {
        switch ($thresholdStatus) {
            case 'out_of_range':
                return 'high';
            case 'above_maximum':
            case 'below_minimum':
                return 'medium';
            default:
                return 'low';
        }
    }

    /**
     * Generate alert message for threshold violation.
     */
    protected function generateThresholdAlertMessage($value, string $thresholdStatus): string
    {
        $formattedValue = $value . ($this->unit ? ' ' . $this->unit : '');

        switch ($thresholdStatus) {
            case 'out_of_range':
                return "Sensor '{$this->name}' reading ({$formattedValue}) is out of acceptable range ({$this->min_threshold}-{$this->max_threshold} {$this->unit})";
            case 'above_maximum':
                return "Sensor '{$this->name}' reading ({$formattedValue}) exceeds maximum threshold ({$this->max_threshold} {$this->unit})";
            case 'below_minimum':
                return "Sensor '{$this->name}' reading ({$formattedValue}) is below minimum threshold ({$this->min_threshold} {$this->unit})";
            default:
                return "Sensor '{$this->name}' threshold violation detected";
        }
    }

    /**
     * Calibrate the sensor with offset and scale factors.
     */
    public function calibrate(float $offset = 0, float $scale = 1): bool
    {
        $calibration = [
            'offset'        => $offset,
            'scale'         => $scale,
            'calibrated_at' => now(),
            'calibrated_by' => auth()->id(),
        ];

        return $this->update(['calibration' => $calibration]);
    }

    /**
     * Apply calibration to a raw sensor value.
     */
    public function applyCalibratedValue(float $rawValue): float
    {
        $calibration = $this->calibration ?? [];
        $offset      = $calibration['offset'] ?? 0;
        $scale       = $calibration['scale'] ?? 1;

        return ($rawValue * $scale) + $offset;
    }

    /**
     * Get sensor reading history.
     */
    public function getReadingHistory(int $limit = 100, int $hours = 24): array
    {
        // This would typically query a separate sensor_readings table
        // For now, return a placeholder structure
        return [
            'sensor_uuid' => $this->uuid,
            'sensor_name' => $this->name,
            'period'      => [
                'start' => now()->subHours($hours),
                'end'   => now(),
            ],
            'readings' => [], // Would contain actual reading data
            'summary'  => [
                'count' => 0,
                'min'   => null,
                'max'   => null,
                'avg'   => null,
                'last'  => $this->last_value,
            ],
        ];
    }

    /**
     * Creates a new position for the vehicle.
     */
    public function createPosition(array $attributes = [], Model|string|null $destination = null): ?Position
    {
        if (!isset($attributes['coordinates']) && isset($attributes['location'])) {
            $attributes['coordinates'] = $attributes['location'];
        }

        if (!isset($attributes['coordinates']) && isset($attributes['latitude']) && isset($attributes['longitude'])) {
            $attributes['coordinates'] = new SpatialPoint($attributes['latitude'], $attributes['longitude']);
        }

        // handle destination if set
        $destinationUuid = Str::isUuid($destination) ? $destination : data_get($destination, 'uuid');

        return Position::create([
            ...Arr::only($attributes, ['coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid']),
            'subject_uuid'     => $this->uuid,
            'subject_type'     => $this->getMorphClass(),
            'company_uuid'     => $this->company_uuid,
            'destination_uuid' => $destinationUuid,
        ]);
    }
}
