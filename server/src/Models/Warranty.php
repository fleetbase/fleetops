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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Warranty.
 *
 * Represents warranty coverage and terms for various items in the fleet management system.
 * Warranties can be attached to assets, equipment, parts, or other entities.
 */
class Warranty extends Model
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
    protected $table = 'warranties';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'warranty';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['provider', 'policy_number', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['provider', 'vendor_uuid', 'subject_type', 'start_date', 'end_date'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'subject_type',
        'subject_uuid',
        'provider',
        'policy_number',
        'start_date',
        'end_date',
        'coverage',
        'terms',
        'policy',
        'vendor_uuid',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'subject_name',
        'vendor_name',
        'is_active',
        'is_expired',
        'days_remaining',
        'coverage_summary',
        'status',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['subject', 'vendor'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'coverage'   => Json::class,
        'terms'      => Json::class,
        'meta'       => Json::class,
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
    protected static $logName = 'warranty';

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subject name.
     */
    public function getSubjectNameAttribute(): ?string
    {
        if ($this->subject) {
            return $this->subject->name ?? $this->subject->display_name ?? null;
        }

        return null;
    }

    /**
     * Get the vendor name.
     */
    public function getVendorNameAttribute(): ?string
    {
        return $this->vendor?->name;
    }

    /**
     * Check if the warranty is currently active.
     */
    public function getIsActiveAttribute(): bool
    {
        $now = now()->toDateString();

        if ($this->start_date && $this->start_date->toDateString() > $now) {
            return false; // Not started yet
        }

        if ($this->end_date && $this->end_date->toDateString() < $now) {
            return false; // Expired
        }

        return true;
    }

    /**
     * Check if the warranty is expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    /**
     * Get the number of days remaining on the warranty.
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (!$this->end_date) {
            return null; // Lifetime warranty
        }

        if ($this->is_expired) {
            return 0;
        }

        return now()->diffInDays($this->end_date, false);
    }

    /**
     * Get a summary of the coverage.
     */
    public function getCoverageSummaryAttribute(): array
    {
        $coverage = $this->coverage ?? [];

        return [
            'parts'      => $coverage['parts'] ?? false,
            'labor'      => $coverage['labor'] ?? false,
            'roadside'   => $coverage['roadside'] ?? false,
            'towing'     => $coverage['towing'] ?? false,
            'rental'     => $coverage['rental'] ?? false,
            'limits'     => $coverage['limits'] ?? null,
            'deductible' => $coverage['deductible'] ?? null,
        ];
    }

    /**
     * Get the warranty status.
     */
    public function getStatusAttribute(): string
    {
        if (!$this->start_date) {
            return 'pending';
        }

        if ($this->start_date->isFuture()) {
            return 'not_started';
        }

        if ($this->is_expired) {
            return 'expired';
        }

        if ($this->days_remaining && $this->days_remaining <= 30) {
            return 'expiring_soon';
        }

        return 'active';
    }

    /**
     * Scope to get active warranties.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('start_date')
              ->orWhere('start_date', '<=', now());
        })->where(function ($q) {
            $q->whereNull('end_date')
              ->orWhere('end_date', '>=', now());
        });
    }

    /**
     * Scope to get expired warranties.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('end_date')
                    ->where('end_date', '<', now());
    }

    /**
     * Scope to get warranties expiring soon.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('end_date')
                    ->whereBetween('end_date', [now(), now()->addDays($days)]);
    }

    /**
     * Check if a specific coverage type is included.
     */
    public function hasCoverage(string $coverageType): bool
    {
        $coverage = $this->coverage ?? [];

        return $coverage[$coverageType] ?? false;
    }

    /**
     * Get the coverage limit for a specific type.
     */
    public function getCoverageLimit(string $coverageType)
    {
        $coverage = $this->coverage ?? [];
        $limits   = $coverage['limits'] ?? [];

        return $limits[$coverageType] ?? null;
    }

    /**
     * Check if the warranty covers a specific repair cost.
     */
    public function coversAmount(string $coverageType, float $cost): bool
    {
        if (!$this->hasCoverage($coverageType)) {
            return false;
        }

        $limit = $this->getCoverageLimit($coverageType);
        if ($limit && $cost > $limit) {
            return false;
        }

        return true;
    }

    /**
     * Get the deductible amount for a coverage type.
     */
    public function getDeductible(string $coverageType): float
    {
        $coverage    = $this->coverage ?? [];
        $deductibles = $coverage['deductible'] ?? [];

        if (is_array($deductibles)) {
            return $deductibles[$coverageType] ?? 0;
        }

        return $deductibles ?? 0;
    }

    /**
     * Check if the warranty is transferable.
     */
    public function isTransferable(): bool
    {
        $terms = $this->terms ?? [];

        return $terms['transferable'] ?? false;
    }

    /**
     * Transfer the warranty to a new subject.
     */
    public function transferTo(Model $newSubject, array $transferData = []): bool
    {
        if (!$this->isTransferable()) {
            return false;
        }

        $oldSubjectType = $this->subject_type;
        $oldSubjectUuid = $this->subject_uuid;

        $updated = $this->update([
            'subject_type' => get_class($newSubject),
            'subject_uuid' => $newSubject->uuid,
        ]);

        if ($updated) {
            activity('warranty_transferred')
                ->performedOn($this)
                ->withProperties([
                    'from_subject_type' => $oldSubjectType,
                    'from_subject_uuid' => $oldSubjectUuid,
                    'to_subject_type'   => get_class($newSubject),
                    'to_subject_uuid'   => $newSubject->uuid,
                    'transfer_data'     => $transferData,
                ])
                ->log('Warranty transferred');
        }

        return $updated;
    }
}
