<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
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
 * Class Device.
 *
 * Represents a physical device that can be mounted or carried, using a telematic
 * for connectivity. Examples include dashcams, OBD devices, hardwired blackboxes, tablets.
 */
class Device extends Model
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
    protected $table = 'devices';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'device';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'model', 'serial_number', 'manufacturer', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['status', 'warranty_uuid', 'attachable_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'telematic_uuid',
        'attachable_uuid',
        'attachable_type',
        'warranty_uuid',
        'photo_uuid',
        'type',
        'device_id',
        'internal_id',
        'imei',
        'imsi',
        'firmware_version',
        'provider',
        'name',
        'model',
        'location',
        'manufacturer',
        'serial_number',
        'last_position',
        'installation_date',
        'last_maintenance_date',
        'meta',
        'data',
        'options',
        'online',
        'status',
        'data_frequency',
        'notes',
        'status',
        'last_online_at',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'warranty_name',
        'telematic_name',
        'is_online',
        'attached_to_name',
        'connection_status',
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
        'installation_date'                  => 'date',
        'last_maintenance_date'              => 'date',
        'last_online_at'                     => 'datetime',
        'last_position'                      => Point::class,
        'meta'                               => Json::class,
        'options'                            => Json::class,
        'data'                               => Json::class,
        'attachable_type'                    => PolymorphicType::class,
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
    protected static $logName = 'device';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name', 'serial_number'])
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

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class, 'device_uuid', 'uuid');
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class, 'device_uuid', 'uuid');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(File::class);
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
     * Get the warranty name.
     */
    public function getWarrantyNameAttribute(): ?string
    {
        return $this->warranty?->name;
    }

    /**
     * Get the telematic name.
     */
    public function getTelematicNameAttribute(): ?string
    {
        return $this->telematic?->name;
    }

    /**
     * Check if the device is currently online.
     */
    public function getIsOnlineAttribute(): bool
    {
        if (!$this->last_online_at) {
            return false;
        }

        // Consider online if last seen within 10 minutes
        return $this->last_online_at->gt(now()->subMinutes(10));
    }

    /**
     * Get the name of what the device is attached to.
     */
    public function getAttachedToNameAttribute(): ?string
    {
        if ($this->attachable) {
            return $this->attachable->name ?? $this->attachable->display_name ?? null;
        }

        return null;
    }

    /**
     * Get the connection status.
     */
    public function getConnectionStatusAttribute(): string
    {
        if (!$this->last_online_at) {
            return 'never_connected';
        }

        $minutesOffline = $this->last_online_at->diffInMinutes(now());

        if ($minutesOffline <= 10) {
            return 'online';
        } elseif ($minutesOffline <= 60) {
            return 'recently_offline';
        } elseif ($minutesOffline <= 1440) { // 24 hours
            return 'offline';
        } else {
            return 'long_offline';
        }
    }

    /**
     * Scope to get online devices.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnline($query)
    {
        return $query->where('last_online_at', '>=', now()->subMinutes(10));
    }

    /**
     * Scope to get offline devices.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOffline($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_online_at')
              ->orWhere('last_online_at', '<', now()->subMinutes(10));
        });
    }

    /**
     * Scope to get devices attached to a specific type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAttachedTo($query, string $type)
    {
        return $query->where('attachable_type', $type);
    }

    /**
     * Update the last online timestamp.
     */
    public function updateLastOnline(): bool
    {
        return $this->update(['last_online_at' => now()]);
    }

    /**
     * Attach the device to an entity.
     */
    public function attachTo(Model $attachable): bool
    {
        $updated = $this->update([
            'attachable_type' => get_class($attachable),
            'attachable_uuid' => $attachable->uuid,
        ]);

        if ($updated) {
            activity('device_attached')
                ->performedOn($this)
                ->withProperties([
                    'attached_to_type' => get_class($attachable),
                    'attached_to_uuid' => $attachable->uuid,
                    'attached_to_name' => $attachable->name ?? $attachable->display_name ?? null,
                ])
                ->log('Device attached');
        }

        return $updated;
    }

    /**
     * Detach the device from its current attachment.
     */
    public function detach(): bool
    {
        $oldAttachableType = $this->attachable_type;
        $oldAttachableUuid = $this->attachable_uuid;

        $updated = $this->update([
            'attachable_type' => null,
            'attachable_uuid' => null,
        ]);

        if ($updated) {
            activity('device_detached')
                ->performedOn($this)
                ->withProperties([
                    'previous_attached_to_type' => $oldAttachableType,
                    'previous_attached_to_uuid' => $oldAttachableUuid,
                ])
                ->log('Device detached');
        }

        return $updated;
    }

    /**
     * Check if the device supports a specific feature.
     */
    public function supportsFeature(string $feature): bool
    {
        $options  = $this->options ?? [];
        $features = $options['supported_features'] ?? [];

        return in_array($feature, $features);
    }

    /**
     * Get the device configuration.
     */
    public function getConfiguration(): array
    {
        return $this->options ?? [];
    }

    /**
     * Update the device configuration.
     */
    public function updateConfiguration(array $config): bool
    {
        $currentOptions = $this->options ?? [];
        $newOptions     = array_merge($currentOptions, $config);

        return $this->update(['options' => $newOptions]);
    }

    /**
     * Send a command to the device via its telematic.
     */
    public function sendCommand(string $command, array $parameters = []): bool
    {
        if (!$this->telematic) {
            return false;
        }

        // Log the command for the device
        activity('device_command')
            ->performedOn($this)
            ->withProperties([
                'command'       => $command,
                'parameters'    => $parameters,
                'via_telematic' => $this->telematic->uuid,
                'timestamp'     => now(),
            ])
            ->log("Command '{$command}' sent to device");

        // Delegate to telematic
        return $this->telematic->sendCommand($command, array_merge($parameters, [
            'target_device' => $this->uuid,
        ]));
    }

    /**
     * Get recent events for the device.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecentEvents(int $limit = 10)
    {
        return $this->events()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
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
