<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\Point;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Illuminate\Support\Str;

class Issue extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use SpatialTrait;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'issues';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'issue';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'reported_by_uuid',
        'assigned_to_uuid',
        'vehicle_uuid',
        'driver_uuid',
        'issue_id',
        'location',
        'category',
        'type',
        'report',
        'priority',
        'meta',
        'resolved_at',
        'status',
    ];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'location'        => Point::class,
        'meta'            => Json::class,
        'resolved_at'     => 'date',
    ];

    /**
     * Filterable attributes/parameters.
     *
     * @var array
     */
    protected $filterParams = ['assignee', 'reporter'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['driver_name', 'vehicle_name', 'assignee_name', 'reporter_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reportedBy()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reporter()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class, 'reported_by_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignedTo()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignee()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class, 'assigned_to_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Set a default status if none is provided, and always dasherize status input.
     *
     * @param string $value
     *
     * @return void
     */
    public function setStatusAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['status'] = 'pending';
        } else {
            $this->attributes['status'] = Str::slug($value);
        }
    }

    /**
     * Get the driver's name assigned to vehicle.
     *
     * @return string
     */
    public function getDriverNameAttribute()
    {
        return data_get($this, 'driver.name');
    }

    /**
     * Get the vehicless name.
     *
     * @return string
     */
    public function getVehicleNameAttribute()
    {
        return data_get($this, 'vehicle.display_name');
    }

    /**
     * Get the reporter name.
     *
     * @return string
     */
    public function getReporterNameAttribute()
    {
        return data_get($this, 'reporter.name');
    }

    /**
     * Get the assignee name.
     *
     * @return string
     */
    public function getAssigneeNameAttribute()
    {
        return data_get($this, 'assignee.name');
    }
}
