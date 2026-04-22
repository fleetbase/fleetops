<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GeofenceEventLog.
 *
 * Persistent audit log of all geofence events (entered, exited, dwelled).
 * Powers the reporting dashboard, dwell time analytics, and geofence
 * activity history views.
 *
 * @property string         $uuid
 * @property string         $company_uuid
 * @property string         $driver_uuid
 * @property string|null    $vehicle_uuid
 * @property string|null    $order_uuid
 * @property string         $geofence_uuid
 * @property string         $geofence_type
 * @property string|null    $geofence_name
 * @property string         $event_type
 * @property float|null     $latitude
 * @property float|null     $longitude
 * @property float|null     $speed_kmh
 * @property int|null       $dwell_duration_minutes
 * @property \Carbon\Carbon $occurred_at
 */
class GeofenceEventLog extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'geofence_events_log';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'company_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'order_uuid',
        'subject_uuid',
        'subject_type',
        'subject_name',
        'geofence_uuid',
        'geofence_type',
        'geofence_name',
        'event_type',
        'latitude',
        'longitude',
        'speed_kmh',
        'dwell_duration_minutes',
        'occurred_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'occurred_at'            => 'datetime',
        'latitude'               => 'float',
        'longitude'              => 'float',
        'speed_kmh'              => 'float',
        'dwell_duration_minutes' => 'integer',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Get the driver associated with this event.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    /**
     * Get the order associated with this event (if any).
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }

    /**
     * Get the vehicle associated with this event (if any).
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    /**
     * Scope to filter by company.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCompany($query, string $companyUuid)
    {
        return $query->where('company_uuid', $companyUuid);
    }

    /**
     * Scope to filter by event type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }
}
