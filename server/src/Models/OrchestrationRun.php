<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One Orchestrator plan run: what it was asked to plan, which engine ran,
 * what it proposed and why each order it left out was left out.
 *
 * `unassigned_reasons` is keyed by order public id:
 *   { "order_x": { "reason": "no_available_vehicle", "message": "..." } }
 */
class OrchestrationRun extends Model
{
    use HasUuid;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'orchestration_runs';

    protected $publicIdType = 'plan';

    protected $fillable = [
        'company_uuid',
        'created_by_uuid',
        'mode',
        'engine',
        'scheduled_date',
        'order_ids',
        'assignments',
        'unassigned',
        'unassigned_reasons',
        'summary',
        'committed_at',
    ];

    protected $casts = [
        'scheduled_date'     => 'date',
        'order_ids'          => Json::class,
        'assignments'        => Json::class,
        'unassigned'         => Json::class,
        'unassigned_reasons' => Json::class,
        'summary'            => Json::class,
        'committed_at'       => 'datetime',
    ];
}
