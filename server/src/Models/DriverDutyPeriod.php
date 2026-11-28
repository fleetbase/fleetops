<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch a driver spent on duty. `ended_at` is null while it is still
 * running. Written only by Support\DriverDuty.
 */
class DriverDutyPeriod extends Model
{
    use HasUuid;

    protected $table = 'driver_duty_periods';

    protected $fillable = ['company_uuid', 'driver_uuid', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }
}
