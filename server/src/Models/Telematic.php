<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Telematic.
 *
 * Represents a telematics device/modem that provides connectivity and data transmission
 * capabilities for fleet assets. This is the "brain" that enables communication between
 * physical devices and the fleet management system.
 */
class Telematic extends Model
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
    protected $table = 'telematics';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'telematic';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'provider', 'model', 'serial_number', 'imei', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['provider', 'status', 'warranty_uuid'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'warranty_uuid',
        'name',
        'provider',
        'model',
        'serial_number',
        'firmware_version',
        'status',
        'imei',
        'iccid',
        'imsi',
        'msisdn',
        'last_seen_at',
        'last_metrics',
        'config',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['warranty_name', 'is_online', 'signal_strength', 'last_location'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['warranty'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_metrics' => Json::class,
        'config'       => Json::class,
        'meta'         => Json::class,
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
    protected static $logName = 'telematic';

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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function device(): HasOne
    {
        return $this->hasOne(Device::class, 'telematic_uuid', 'uuid');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'telematic_uuid', 'uuid');
    }

    /**
     * Get the warranty name.
     */
    public function getWarrantyNameAttribute(): ?string
    {
        return $this->warranty?->name;
    }

    /**
     * Check if the telematic device is currently online.
     */
    public function getIsOnlineAttribute(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        // Consider online if last seen within 5 minutes
        return $this->last_seen_at->gt(now()->subMinutes(5));
    }

    /**
     * Get the signal strength from last metrics.
     */
    public function getSignalStrengthAttribute(): ?int
    {
        return $this->last_metrics['signal_strength'] ?? null;
    }

    /**
     * Get the last known location from metrics.
     */
    public function getLastLocationAttribute(): ?array
    {
        $metrics = $this->last_metrics;

        if (isset($metrics['lat']) && isset($metrics['lng'])) {
            return [
                'latitude'  => $metrics['lat'],
                'longitude' => $metrics['lng'],
                'timestamp' => $this->last_seen_at?->toISOString(),
            ];
        }

        return null;
    }

    /**
     * Scope to get online telematics.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnline($query)
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes(5));
    }

    /**
     * Scope to get offline telematics.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOffline($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_seen_at')
              ->orWhere('last_seen_at', '<', now()->subMinutes(5));
        });
    }

    /**
     * Scope to get telematics by provider.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Update the last seen timestamp and metrics.
     */
    public function updateHeartbeat(array $metrics = []): bool
    {
        return $this->update([
            'last_seen_at' => now(),
            'last_metrics' => array_merge($this->last_metrics ?? [], $metrics),
        ]);
    }

    /**
     * Get the connection status based on last seen time.
     */
    public function getConnectionStatus(): string
    {
        if (!$this->last_seen_at) {
            return 'never_connected';
        }

        $minutesOffline = $this->last_seen_at->diffInMinutes(now());

        if ($minutesOffline <= 5) {
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
     * Check if the telematic supports a specific feature.
     */
    public function supportsFeature(string $feature): bool
    {
        $config   = $this->config ?? [];
        $features = $config['supported_features'] ?? [];

        return in_array($feature, $features);
    }

    /**
     * Get the firmware update status.
     */
    public function getFirmwareStatus(): array
    {
        $config = $this->config ?? [];

        return [
            'current_version'  => $this->firmware_version,
            'latest_version'   => $config['latest_firmware'] ?? null,
            'update_available' => isset($config['latest_firmware'])
                                && version_compare($this->firmware_version, $config['latest_firmware'], '<'),
        ];
    }

    /**
     * Send a command to the telematic device.
     */
    public function sendCommand(string $command, array $parameters = []): bool
    {
        // This would integrate with the actual telematic provider's API
        // For now, we'll just log the command attempt

        activity('telematic_command')
            ->performedOn($this)
            ->withProperties([
                'command'    => $command,
                'parameters' => $parameters,
                'timestamp'  => now(),
            ])
            ->log("Command '{$command}' sent to telematic device");

        return true;
    }
}
