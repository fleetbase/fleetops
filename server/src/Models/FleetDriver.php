<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;

class FleetDriver extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'fleet_drivers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['fleet_uuid', 'driver_uuid'];

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * also search joined properties.
     *
     * @var array
     */
    protected $alsoSearch = [['driver' => ['user' => 'name']]];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The group of the membership.
     *
     * @var Model
     */
    public function fleet()
    {
        return $this->belongsTo(Fleet::class);
    }

    /**
     * The driver.
     *
     * @var Model
     */
    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
