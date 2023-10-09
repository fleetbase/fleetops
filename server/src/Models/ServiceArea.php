<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\FleetOps\Casts\MultiPolygon;
use Fleetbase\FleetOps\Support\Utils;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;

class ServiceArea extends Model
{
    use HasUuid, HasPublicId, SendsWebhooks, TracksApiCredential, SpatialTrait, HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'service_areas';

    /**
     * The type of public Id to generate
     *
     * @var string
     */
    protected $publicIdType = 'sa';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'company_uuid', 'name', 'type', 'parent_uuid', 'border', 'color', 'stroke_color', 'status'];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['border'];

    /**
     * Relationships to load with model.
     *
     * @var array
     */
    protected $with = ['zones'];

    /**
     * Dynamic attributes that are appended to object
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
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'border' => MultiPolygon::class,
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function zones()
    {
        return $this->hasMany(Zone::class)->without(['serviceArea']);
    }

    /**
     * Sets the status attribute for the model.
     *
     * @param string|null $status The status value, defaults to 'active' if not provided.
     * @return void
     */
    public function setStatusAttribute(?string $status = 'active')
    {
        $this->attributes['status'] = $status;
    }

    /**
     * Sets the type attribute for the model.
     *
     * @param string|null $type The type value, defaults to 'country' if not provided.
     * @return void
     */
    public function setTypeAttribute(?string $type = 'country')
    {
        $this->attributes['type'] = $type;
    }

    /**
     * Creates a 100m polygon from the coorddinates
     *
     * @var Polygon
     */
    public function polygon($meters = 100)
    {
        return new \League\Geotools\Polygon\Polygon(Utils::coordsToCircle($this->latitude, $this->longitude, $meters));
    }

    /**
     * Determines if coordinates fall within zone
     *
     * @param integer $latitude
     * @param integer $longitude
     * @return boolean
     */
    public function inZone($latitude, $longitude)
    {
        return $this->polygon()->pointInPolygon(new \League\Geotools\Coordinate\Coordinate([$latitude, $longitude]));
    }

    /**
     * Determines if multiple coordinates fall within zone
     *
     * @param array $coords
     * @return boolean
     */
    public function pointsInZone($coords)
    {
        foreach ($coords as $coord) {
            if (!$this->polygon()->pointInPolygon(new \League\Geotools\Coordinate\Coordinate([$coord[0], $coord[1]]))) {
                return false;
            }
        }
        return true;
    }
}
