<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;

/**
 * One driver's turn to accept an order: `pending` until `expires_at`, then `accepted`,
 * `declined`, `expired` or `withdrawn`.
 */
class OrderOffer extends Model
{
    use HasUuid;

    public const PENDING   = 'pending';
    public const ACCEPTED  = 'accepted';
    public const DECLINED  = 'declined';
    public const EXPIRED   = 'expired';
    public const WITHDRAWN = 'withdrawn';

    protected $table = 'order_offers';

    protected $fillable = ['company_uuid', 'order_uuid', 'driver_uuid', 'status', 'distance', 'expires_at', 'responded_at'];

    protected $casts = [
        'distance'     => 'integer',
        'expires_at'   => 'datetime',
        'responded_at' => 'datetime',
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
