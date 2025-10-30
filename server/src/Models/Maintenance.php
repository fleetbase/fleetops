<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Maintenance.
 *
 * Tracks service actions performed on maintainable entities (Assets, Equipment).
 * Includes scheduling, execution, costs, and documentation of maintenance activities.
 */
class Maintenance extends Model
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
    protected $table = 'maintenances';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'maintenance';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['summary', 'notes', 'type', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['type', 'status', 'priority', 'maintainable_type', 'work_order_uuid', 'performed_by_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'work_order_uuid',
        'maintainable_type',
        'maintainable_uuid',
        'type',
        'status',
        'priority',
        'scheduled_at',
        'started_at',
        'completed_at',
        'odometer',
        'engine_hours',
        'performed_by_type',
        'performed_by_uuid',
        'summary',
        'notes',
        'line_items',
        'labor_cost',
        'parts_cost',
        'tax',
        'total_cost',
        'attachments',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'maintainable_name',
        'work_order_subject',
        'performed_by_name',
        'duration_hours',
        'is_overdue',
        'days_until_due',
        'cost_breakdown',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['maintainable', 'workOrder', 'performedBy'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'scheduled_at'      => 'datetime',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'odometer'          => 'integer',
        'engine_hours'      => 'integer',
        'labor_cost'        => 'decimal:2',
        'parts_cost'        => 'decimal:2',
        'tax'               => 'decimal:2',
        'total_cost'        => 'decimal:2',
        'line_items'        => Json::class,
        'attachments'       => Json::class,
        'meta'              => Json::class,
        'maintainable_type' => PolymorphicType::class,
        'performed_by_type' => PolymorphicType::class,
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
    protected static $logName = 'maintenance';

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function maintainable(): MorphTo
    {
        return $this->morphTo();
    }

    public function performedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the maintainable entity name.
     */
    public function getMaintainableNameAttribute(): ?string
    {
        if ($this->maintainable) {
            return $this->maintainable->name ?? $this->maintainable->display_name ?? null;
        }

        return null;
    }

    /**
     * Get the work order subject.
     */
    public function getWorkOrderSubjectAttribute(): ?string
    {
        return $this->workOrder?->subject;
    }

    /**
     * Get the name of who performed the maintenance.
     */
    public function getPerformedByNameAttribute(): ?string
    {
        if ($this->performedBy) {
            return $this->performedBy->name ?? $this->performedBy->display_name ?? null;
        }

        return null;
    }

    /**
     * Get the duration in hours.
     */
    public function getDurationHoursAttribute(): ?float
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInHours($this->completed_at);
        }

        return null;
    }

    /**
     * Check if the maintenance is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (!$this->scheduled_at || $this->status === 'completed') {
            return false;
        }

        return now()->gt($this->scheduled_at);
    }

    /**
     * Get the number of days until due.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->scheduled_at || $this->status === 'completed') {
            return null;
        }

        return now()->diffInDays($this->scheduled_at, false);
    }

    /**
     * Get the cost breakdown.
     */
    public function getCostBreakdownAttribute(): array
    {
        return [
            'labor_cost' => $this->labor_cost ?? 0,
            'parts_cost' => $this->parts_cost ?? 0,
            'tax'        => $this->tax ?? 0,
            'subtotal'   => ($this->labor_cost ?? 0) + ($this->parts_cost ?? 0),
            'total_cost' => $this->total_cost ?? 0,
        ];
    }

    /**
     * Scope to get maintenance by status.
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
     * Scope to get scheduled maintenance.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope to get overdue maintenance.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('scheduled_at', '<', now())
                    ->where('status', '!=', 'completed');
    }

    /**
     * Scope to get maintenance by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get maintenance by priority.
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
     * Start the maintenance.
     */
    public function start(?Model $performedBy = null): bool
    {
        if ($this->status !== 'scheduled') {
            return false;
        }

        $updateData = [
            'status'     => 'in_progress',
            'started_at' => now(),
        ];

        if ($performedBy) {
            $updateData['performed_by_type'] = get_class($performedBy);
            $updateData['performed_by_uuid'] = $performedBy->uuid;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('maintenance_started')
                ->performedOn($this)
                ->withProperties([
                    'performed_by_type' => $updateData['performed_by_type'] ?? null,
                    'performed_by_uuid' => $updateData['performed_by_uuid'] ?? null,
                ])
                ->log('Maintenance started');
        }

        return $updated;
    }

    /**
     * Complete the maintenance.
     */
    public function complete(array $completionData = []): bool
    {
        if (!in_array($this->status, ['scheduled', 'in_progress'])) {
            return false;
        }

        $updateData = [
            'status'       => 'completed',
            'completed_at' => now(),
        ];

        // Update costs if provided
        if (isset($completionData['labor_cost'])) {
            $updateData['labor_cost'] = $completionData['labor_cost'];
        }
        if (isset($completionData['parts_cost'])) {
            $updateData['parts_cost'] = $completionData['parts_cost'];
        }
        if (isset($completionData['tax'])) {
            $updateData['tax'] = $completionData['tax'];
        }

        // Calculate total cost
        $laborCost                = $updateData['labor_cost'] ?? $this->labor_cost ?? 0;
        $partsCost                = $updateData['parts_cost'] ?? $this->parts_cost ?? 0;
        $tax                      = $updateData['tax'] ?? $this->tax ?? 0;
        $updateData['total_cost'] = $laborCost + $partsCost + $tax;

        // Update other fields
        if (isset($completionData['notes'])) {
            $updateData['notes'] = $completionData['notes'];
        }
        if (isset($completionData['line_items'])) {
            $updateData['line_items'] = $completionData['line_items'];
        }
        if (isset($completionData['attachments'])) {
            $updateData['attachments'] = $completionData['attachments'];
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('maintenance_completed')
                ->performedOn($this)
                ->withProperties($completionData)
                ->log('Maintenance completed');

            // Update the maintainable entity's odometer/engine hours if provided
            if ($this->maintainable && isset($completionData['odometer'])) {
                $this->maintainable->updateOdometer($completionData['odometer'], 'maintenance');
            }
            if ($this->maintainable && isset($completionData['engine_hours'])) {
                $this->maintainable->updateEngineHours($completionData['engine_hours'], 'maintenance');
            }
        }

        return $updated;
    }

    /**
     * Cancel the maintenance.
     */
    public function cancel(?string $reason = null): bool
    {
        if ($this->status === 'completed') {
            return false;
        }

        $updateData = ['status' => 'canceled'];

        if ($reason) {
            $meta                        = $this->meta ?? [];
            $meta['cancellation_reason'] = $reason;
            $updateData['meta']          = $meta;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('maintenance_canceled')
                ->performedOn($this)
                ->withProperties(['reason' => $reason])
                ->log('Maintenance canceled');
        }

        return $updated;
    }

    /**
     * Add a line item to the maintenance.
     */
    public function addLineItem(array $item): bool
    {
        $lineItems   = $this->line_items ?? [];
        $lineItems[] = array_merge($item, [
            'added_at' => now(),
        ]);

        return $this->update(['line_items' => $lineItems]);
    }

    /**
     * Remove a line item from the maintenance.
     */
    public function removeLineItem(int $index): bool
    {
        $lineItems = $this->line_items ?? [];

        if (!isset($lineItems[$index])) {
            return false;
        }

        unset($lineItems[$index]);
        $lineItems = array_values($lineItems); // Re-index array

        return $this->update(['line_items' => $lineItems]);
    }

    /**
     * Add an attachment to the maintenance.
     */
    public function addAttachment(string $fileUuid, ?string $description = null): bool
    {
        $attachments   = $this->attachments ?? [];
        $attachments[] = [
            'file_uuid'   => $fileUuid,
            'description' => $description,
            'added_at'    => now(),
        ];

        return $this->update(['attachments' => $attachments]);
    }

    /**
     * Get the efficiency rating based on scheduled vs actual time.
     */
    public function getEfficiencyRating(): ?float
    {
        if (!$this->duration_hours || !$this->scheduled_at || !$this->completed_at) {
            return null;
        }

        $meta           = $this->meta ?? [];
        $estimatedHours = $meta['estimated_duration_hours'] ?? null;

        if (!$estimatedHours) {
            return null;
        }

        // Efficiency = estimated / actual (higher is better)
        return min(($estimatedHours / $this->duration_hours) * 100, 100);
    }

    /**
     * Check if the maintenance was completed on time.
     */
    public function wasCompletedOnTime(): ?bool
    {
        if (!$this->scheduled_at || !$this->completed_at) {
            return null;
        }

        return $this->completed_at->lte($this->scheduled_at);
    }

    /**
     * Get the cost per hour of maintenance.
     */
    public function getCostPerHour(): ?float
    {
        if (!$this->duration_hours || !$this->total_cost) {
            return null;
        }

        return $this->total_cost / $this->duration_hours;
    }
}
