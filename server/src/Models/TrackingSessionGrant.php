<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;

/**
 * What a verified device may see: one customer within one order, until `expires_at`.
 */
class TrackingSessionGrant extends Model
{
    use HasUuid;

    protected $table = 'tracking_session_grants';

    protected $fillable = ['tracking_session_uuid', 'company_uuid', 'order_uuid', 'customer_type', 'customer_uuid', 'channel', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
