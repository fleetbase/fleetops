<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasCustomFields;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InspectionSubmission extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use HasCustomFields;

    protected $table = 'inspection_submissions';
    protected $publicIdType = 'inspection_submission';
    protected $searchableColumns = ['public_id', 'type', 'status', 'result', 'vehicle.name', 'driver.name'];
    protected $filterParams = ['status', 'result', 'type', 'source', 'vehicle', 'driver', 'inspection_form_uuid'];

    protected $fillable = [
        'company_uuid',
        'inspection_form_uuid',
        'vehicle_uuid',
        'driver_uuid',
        'submitted_by_uuid',
        'issue_uuid',
        'work_order_uuid',
        'type',
        'status',
        'result',
        'source',
        'odometer',
        'engine_hours',
        'total_items',
        'failed_items',
        'started_at',
        'submitted_at',
        'resolved_at',
        'location',
        'signature',
        'attachments',
        'meta',
        'created_by_uuid',
        'updated_by_uuid',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
        'resolved_at'  => 'datetime',
        'location'     => Json::class,
        'signature'    => Json::class,
        'attachments'  => Json::class,
        'meta'         => Json::class,
        'odometer'     => 'integer',
        'engine_hours' => 'integer',
        'total_items'  => 'integer',
        'failed_items' => 'integer',
    ];

    protected $appends = ['form_name', 'vehicle_name', 'driver_name', 'has_failures'];
    protected $with = ['form', 'vehicle', 'driver'];

    protected static $logName = 'inspection_submission';
    protected static $logAttributes = '*';
    protected static $submitEmptyLogs = false;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(InspectionForm::class, 'inspection_form_uuid', 'uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_uuid', 'uuid');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_uuid', 'uuid');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_uuid', 'uuid');
    }

    public function itemResults(): HasMany
    {
        return $this->hasMany(InspectionItemResult::class, 'inspection_submission_uuid', 'uuid');
    }

    public function failedItemResults(): HasMany
    {
        return $this->itemResults()->where('passed', false);
    }

    public function getFormNameAttribute(): ?string
    {
        return $this->form?->name;
    }

    public function getVehicleNameAttribute(): ?string
    {
        return $this->vehicle?->display_name ?? $this->vehicle?->name;
    }

    public function getDriverNameAttribute(): ?string
    {
        return $this->driver?->name;
    }

    public function getHasFailuresAttribute(): bool
    {
        return (int) $this->failed_items > 0 || $this->result === 'failed';
    }

    public function syncResultCounts(): bool
    {
        $total = $this->itemResults()->count();
        $failed = $this->failedItemResults()->count();

        return $this->update([
            'total_items'  => $total,
            'failed_items' => $failed,
            'result'       => $failed > 0 ? 'failed' : 'passed',
            'status'       => $this->status === 'draft' ? 'submitted' : $this->status,
            'submitted_at' => $this->submitted_at ?? now(),
        ]);
    }

    public function createIssueFromFailures(): ?Issue
    {
        if (!$this->has_failures || $this->issue_uuid) {
            return $this->issue;
        }

        $failedLabels = $this->failedItemResults()->limit(6)->pluck('label')->filter()->values()->all();
        $issue = Issue::create([
            'company_uuid'      => $this->company_uuid,
            'reported_by_uuid'  => $this->submitted_by_uuid,
            'vehicle_uuid'      => $this->vehicle_uuid,
            'driver_uuid'       => $this->driver_uuid,
            'type'              => 'inspection',
            'category'          => 'inspection_failed',
            'title'             => 'Failed inspection: ' . ($this->vehicle_name ?? $this->public_id),
            'report'            => empty($failedLabels) ? 'Inspection failed.' : 'Failed items: ' . implode(', ', $failedLabels),
            'priority'          => $this->highestFailureSeverity(),
            'status'            => 'pending',
            'meta'              => [
                'inspection_submission_uuid' => $this->uuid,
                'inspection_submission_id'   => $this->public_id,
                'inspection_form_uuid'       => $this->inspection_form_uuid,
                'failed_items'               => $failedLabels,
            ],
        ]);

        $this->update(['issue_uuid' => $issue->uuid]);

        return $issue;
    }

    public function createWorkOrderFromFailures(): ?WorkOrder
    {
        if (!$this->has_failures || $this->work_order_uuid) {
            return $this->workOrder;
        }

        $failedItems = $this->failedItemResults()->get();
        $checklist = $failedItems->map(fn (InspectionItemResult $item) => [
            'title'      => $item->label,
            'required'   => true,
            'completed'  => false,
            'source'     => 'inspection',
            'item_key'   => $item->item_key,
            'severity'   => $item->severity,
            'created_at' => now(),
        ])->values()->all();

        $workOrder = WorkOrder::create([
            'company_uuid'  => $this->company_uuid,
            'subject'       => 'Inspection repair: ' . ($this->vehicle_name ?? $this->public_id),
            'status'        => 'open',
            'priority'      => $this->highestFailureSeverity(),
            'target_type'   => $this->vehicle_uuid ? Vehicle::class : null,
            'target_uuid'   => $this->vehicle_uuid,
            'opened_at'     => now(),
            'due_at'        => now()->addDays($this->highestFailureSeverity() === 'critical' ? 1 : 7),
            'instructions'  => 'Resolve failed inspection items and record completion details.',
            'checklist'     => $checklist,
            'currency'      => $this->vehicle?->currency,
            'created_by_uuid' => $this->submitted_by_uuid,
            'meta'          => [
                'source'                     => 'inspection',
                'inspection_submission_uuid' => $this->uuid,
                'inspection_submission_id'   => $this->public_id,
                'issue_uuid'                 => $this->issue_uuid,
            ],
        ]);

        $this->update(['work_order_uuid' => $workOrder->uuid]);
        $this->failedItemResults()->update(['work_order_uuid' => $workOrder->uuid]);

        return $workOrder;
    }

    public function highestFailureSeverity(): string
    {
        $severity = $this->failedItemResults()
            ->pluck('severity')
            ->map(fn ($value) => Str::slug((string) $value))
            ->filter()
            ->all();

        foreach (['critical', 'high', 'medium', 'low'] as $candidate) {
            if (in_array($candidate, $severity, true)) {
                return $candidate;
            }
        }

        return $this->has_failures ? 'high' : 'low';
    }
}
