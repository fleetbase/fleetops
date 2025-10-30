<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\PolymorphicType;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Position extends Model
{
    use HasUuid;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use SpatialTrait;

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['uuid', 'company_uuid', 'order_uuid', 'destination_uuid', 'subject_uuid', 'subject_type', 'coordinates', 'heading', 'bearing', 'speed', 'altitude'];

    /**
     * The attributes that are spatial fields.
     *
     * @var array
     */
    protected $spatialFields = ['coordinates'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $with = [];

    /**
     * Dynamic attributes.
     *
     * @var array
     */
    protected $appends = ['latitude', 'longitude'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'subject_type' => PolymorphicType::class,
    ];

    /**
     * Get filter parameters for this model.
     *
     * @var array
     */
    protected $filterParams = [];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = ['coordinates', 'heading', 'bearing', 'speed', 'altitude'];

    /**
     * We only want to log changed attributes.
     *
     * @var bool
     */
    protected static $logOnlyDirty = true;

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
    protected static $logName = 'position';

    public function company(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid')->withoutGlobalScopes();
    }

    public function getLongitudeAttribute(): float
    {
        return $this->coordinates?->getLng() ?? 0;
    }

    public function getLatitudeAttribute(): float
    {
        return $this->coordinates?->getLat() ?? 0;
    }
}
