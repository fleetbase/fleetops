<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InspectionItemResult extends Model
{
    use HasUuid;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;

    protected $table = 'inspection_item_results';
    protected $searchableColumns = ['label', 'category', 'comments'];
    protected $filterParams = ['status', 'severity', 'passed', 'inspection_submission_uuid', 'issue_uuid', 'work_order_uuid'];

    protected $fillable = [
        'company_uuid',
        'inspection_submission_uuid',
        'issue_uuid',
        'work_order_uuid',
        'item_key',
        'label',
        'category',
        'status',
        'severity',
        'passed',
        'comments',
        'photos',
        'meta',
        'created_by_uuid',
        'updated_by_uuid',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'photos' => Json::class,
        'meta'   => Json::class,
    ];

    protected $appends = ['submission_id'];
    protected $with = [];

    protected static $logName = 'inspection_item_result';
    protected static $logAttributes = '*';
    protected static $submitEmptyLogs = false;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(InspectionSubmission::class, 'inspection_submission_uuid', 'uuid');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_uuid', 'uuid');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function getSubmissionIdAttribute(): ?string
    {
        return $this->submission?->public_id;
    }
}
