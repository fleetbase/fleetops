<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;

/**
 * A customer's stars, from 1 to 5, for the driver of a finished order. One per order.
 */
class DriverRating extends Model
{
    use HasUuid;

    protected $table = 'driver_ratings';

    protected $fillable = ['company_uuid', 'order_uuid', 'driver_uuid', 'customer_uuid', 'customer_type', 'stars', 'comment'];

    protected $casts = [
        'stars' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid')->withoutGlobalScopes();
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid')->withoutGlobalScopes();
    }
}
