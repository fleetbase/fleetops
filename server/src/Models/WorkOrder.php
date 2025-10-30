<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\File;
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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class WorkOrder.
 *
 * Represents an operational task wrapper that coordinates who, when, and what
 * for maintenance or other jobs in the fleet management system.
 */
class WorkOrder extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use HasCustomFields;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'work_orders';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'work_order';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['code', 'subject', 'instructions', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['status', 'priority', 'target_type', 'assignee_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'code',
        'subject',
        'status',
        'priority',
        'target_type',
        'target_uuid',
        'assignee_type',
        'assignee_uuid',
        'opened_at',
        'due_at',
        'closed_at',
        'instructions',
        'checklist',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'target_name',
        'assignee_name',
        'is_overdue',
        'days_until_due',
        'completion_percentage',
        'estimated_duration',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['target', 'assignee'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'opened_at'     => 'datetime',
        'due_at'        => 'datetime',
        'closed_at'     => 'datetime',
        'checklist'     => Json::class,
        'meta'          => Json::class,
        'target_type'   => PolymorphicType::class,
        'assignee_type' => PolymorphicType::class,
    ];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = '*';

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
    protected static $logName = 'work_order';

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'work_order_uuid', 'uuid');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(File::class, 'subject_uuid', 'uuid');
    }

    /**
     * Get the target name.
     */
    public function getTargetNameAttribute(): ?string
    {
        if ($this->target) {
            return $this->target->name ?? $this->target->display_name ?? null;
        }

        return null;
    }

    /**
     * Get the assignee name.
     */
    public function getAssigneeNameAttribute(): ?string
    {
        if ($this->assignee) {
            return $this->assignee->name ?? $this->assignee->display_name ?? null;
        }

        return null;
    }

    /**
     * Check if the work order is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (!$this->due_at || $this->status === 'closed') {
            return false;
        }

        return now()->gt($this->due_at);
    }

    /**
     * Get the number of days until due.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->due_at || $this->status === 'closed') {
            return null;
        }

        $days = now()->diffInDays($this->due_at, false);

        return $days;
    }

    /**
     * Get the completion percentage based on checklist.
     */
    public function getCompletionPercentageAttribute(): float
    {
        $checklist = $this->checklist ?? [];

        if (empty($checklist)) {
            return $this->status === 'closed' ? 100.0 : 0.0;
        }

        $totalItems     = count($checklist);
        $completedItems = 0;

        foreach ($checklist as $item) {
            if (isset($item['completed']) && $item['completed']) {
                $completedItems++;
            }
        }

        return $totalItems > 0 ? ($completedItems / $totalItems) * 100 : 0.0;
    }

    /**
     * Get the estimated duration in hours.
     */
    public function getEstimatedDurationAttribute(): ?float
    {
        $meta = $this->meta ?? [];

        return $meta['estimated_duration_hours'] ?? null;
    }

    /**
     * Scope to get work orders by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get open work orders.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    /**
     * Scope to get overdue work orders.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('due_at', '<', now())
                    ->whereNotIn('status', ['closed', 'canceled']);
    }

    /**
     * Scope to get work orders by priority.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to get work orders assigned to a specific entity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAssignedTo($query, string $type, string $uuid)
    {
        return $query->where('assignee_type', $type)
                    ->where('assignee_uuid', $uuid);
    }

    /**
     * Assign the work order to an entity.
     */
    public function assignTo(Model $assignee): bool
    {
        $updated = $this->update([
            'assignee_type' => get_class($assignee),
            'assignee_uuid' => $assignee->uuid,
        ]);

        if ($updated) {
            activity('work_order_assigned')
                ->performedOn($this)
                ->withProperties([
                    'assigned_to_type' => get_class($assignee),
                    'assigned_to_uuid' => $assignee->uuid,
                    'assigned_to_name' => $assignee->name ?? $assignee->display_name ?? null,
                ])
                ->log('Work order assigned');
        }

        return $updated;
    }

    /**
     * Start the work order.
     */
    public function start(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $updated = $this->update([
            'status'    => 'in_progress',
            'opened_at' => $this->opened_at ?? now(),
        ]);

        if ($updated) {
            activity('work_order_started')
                ->performedOn($this)
                ->log('Work order started');
        }

        return $updated;
    }

    /**
     * Complete the work order.
     */
    public function complete(array $completionData = []): bool
    {
        if (!in_array($this->status, ['open', 'in_progress'])) {
            return false;
        }

        $updateData = [
            'status'    => 'closed',
            'closed_at' => now(),
        ];

        // Update meta with completion data
        if (!empty($completionData)) {
            $meta                    = $this->meta ?? [];
            $meta['completion_data'] = $completionData;
            $updateData['meta']      = $meta;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('work_order_completed')
                ->performedOn($this)
                ->withProperties($completionData)
                ->log('Work order completed');
        }

        return $updated;
    }

    /**
     * Cancel the work order.
     */
    public function cancel(?string $reason = null): bool
    {
        if ($this->status === 'closed') {
            return false;
        }

        $updateData = [
            'status'    => 'canceled',
            'closed_at' => now(),
        ];

        if ($reason) {
            $meta                        = $this->meta ?? [];
            $meta['cancellation_reason'] = $reason;
            $updateData['meta']          = $meta;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('work_order_canceled')
                ->performedOn($this)
                ->withProperties(['reason' => $reason])
                ->log('Work order canceled');
        }

        return $updated;
    }

    /**
     * Update a checklist item.
     */
    public function updateChecklistItem(int $itemIndex, array $itemData): bool
    {
        $checklist = $this->checklist ?? [];

        if (!isset($checklist[$itemIndex])) {
            return false;
        }

        $checklist[$itemIndex] = array_merge($checklist[$itemIndex], $itemData);

        return $this->update(['checklist' => $checklist]);
    }

    /**
     * Mark a checklist item as completed.
     */
    public function completeChecklistItem(int $itemIndex, ?string $completedBy = null): bool
    {
        return $this->updateChecklistItem($itemIndex, [
            'completed'    => true,
            'completed_at' => now(),
            'completed_by' => $completedBy ?? auth()->id(),
        ]);
    }

    /**
     * Add a new checklist item.
     */
    public function addChecklistItem(array $item): bool
    {
        $checklist   = $this->checklist ?? [];
        $checklist[] = array_merge($item, [
            'completed'  => false,
            'created_at' => now(),
        ]);

        return $this->update(['checklist' => $checklist]);
    }

    /**
     * Get the actual duration of the work order.
     *
     * @return float|null Hours
     */
    public function getActualDuration(): ?float
    {
        if (!$this->opened_at || !$this->closed_at) {
            return null;
        }

        return $this->opened_at->diffInHours($this->closed_at);
    }

    /**
     * Check if the work order is on schedule.
     */
    public function isOnSchedule(): ?bool
    {
        if (!$this->due_at) {
            return null;
        }

        if ($this->status === 'closed') {
            return $this->closed_at->lte($this->due_at);
        }

        // For open work orders, check if we're still within the due date
        return now()->lte($this->due_at);
    }

    /**
     * Get the priority level as a numeric value for sorting.
     */
    public function getPriorityLevel(): int
    {
        switch ($this->priority) {
            case 'critical':
                return 5;
            case 'high':
                return 4;
            case 'medium':
                return 3;
            case 'low':
                return 2;
            default:
                return 1;
        }
    }
}
