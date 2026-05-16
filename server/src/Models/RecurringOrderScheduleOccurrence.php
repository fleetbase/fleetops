<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurringOrderScheduleOccurrence extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use TracksApiCredential;
    use HasMetaAttributes;
    use SoftDeletes;

    protected $table = 'recurring_order_schedule_occurrences';

    protected $publicIdType = 'recurring_occurrence';

    protected $fillable = [
        '_key',
        'public_id',
        'company_uuid',
        'recurring_order_schedule_uuid',
        'order_uuid',
        'occurrence_at',
        'status',
        'reason',
        'meta',
        'created_by_uuid',
        'updated_by_uuid',
    ];

    protected $casts = [
        'meta'          => Json::class,
        'occurrence_at' => 'datetime',
    ];

    protected $filterParams = ['status', 'order_uuid', 'recurring_order_schedule_uuid'];

    public function recurringOrderSchedule(): BelongsTo
    {
        return $this->belongsTo(RecurringOrderSchedule::class, 'recurring_order_schedule_uuid', 'uuid');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid')->withoutGlobalScopes();
    }
}
