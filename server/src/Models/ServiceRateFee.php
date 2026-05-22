<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Money;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRateFee extends Model
{
    use HasUuid;
    use TracksApiCredential;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'service_rate_fees';

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
        'service_rate_uuid',
        'service_area_uuid',
        'zone_uuid',
        'label',
        'priority',
        'is_fallback',
        'distance',
        'distance_unit',
        'min',
        'max',
        'unit',
        'fee',
        'currency',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'min'         => 'integer',
        'max'         => 'integer',
        'fee'         => Money::class,
        'distance'    => 'integer',
        'priority'    => 'integer',
        'is_fallback' => 'boolean',
    ];

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

    public static function onRowInsert($row)
    {
        $row['fee']         = Money::apply($row['fee'] ?? 0);
        $row['distance']    = Utils::numbersOnly($row['distance'] ?? null);
        $row['min']         = Utils::numbersOnly($row['min'] ?? null);
        $row['max']         = Utils::numbersOnly($row['max'] ?? null);
        $row['priority']    = Utils::numbersOnly($row['priority'] ?? 0);
        $row['is_fallback'] = Utils::castBoolean($row['is_fallback'] ?? false);

        return $row;
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class, 'service_area_uuid', 'uuid');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_uuid', 'uuid');
    }

    public function setDistanceAttribute($value)
    {
        $this->attributes['distance'] = Utils::numbersOnly($value);
    }

    public function isWithinMinMax(int $number = 0): bool
    {
        return $number >= $this->min && $number <= $this->max;
    }
}
