<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Fleetbase\FleetOps\Casts\Polygon;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;

class Zone extends Model
{
    use HasUuid,
        HasPublicId,
        SendsWebhooks,
        TracksApiCredential,
        SpatialTrait,
        HasApiModelBehavior;

    /**
     * The type of public Id to generate
     *
     * @var string
     */
    protected $publicIdType = 'zone';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'zones';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['border'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'company_uuid', 'service_area_uuid', 'name', 'description', 'border', 'color', 'stroke_color', 'status'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'border' => Polygon::class,
    ];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = ['type'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Service area for rate.
     *
     * @var Model
     */
    public function serviceArea()
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function getTypeAttribute()
    {
        return 'zone';
    }
}
