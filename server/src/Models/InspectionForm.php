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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InspectionForm extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use HasCustomFields;

    protected $table = 'inspection_forms';
    protected $publicIdType = 'inspection_form';
    protected $searchableColumns = ['name', 'description', 'type', 'public_id'];
    protected $filterParams = ['status', 'type', 'frequency', 'subject_type', 'subject_uuid'];

    protected $fillable = [
        'company_uuid',
        'name',
        'description',
        'type',
        'status',
        'frequency',
        'subject_type',
        'subject_uuid',
        'items',
        'settings',
        'meta',
        'published_at',
        'created_by_uuid',
        'updated_by_uuid',
    ];

    protected $casts = [
        'items'        => Json::class,
        'settings'     => Json::class,
        'meta'         => Json::class,
        'published_at' => 'datetime',
        'subject_type' => PolymorphicType::class,
    ];

    protected $appends = ['subject_name', 'item_count', 'is_published'];
    protected $with = ['subject'];

    protected static $logName = 'inspection_form';
    protected static $logAttributes = '*';
    protected static $submitEmptyLogs = false;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(InspectionSubmission::class, 'inspection_form_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function getSubjectNameAttribute(): ?string
    {
        return $this->subject?->name ?? $this->subject?->display_name ?? $this->subject?->public_id;
    }

    public function getItemCountAttribute(): int
    {
        return count($this->items ?? []);
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->status === 'published' && $this->published_at !== null;
    }

    public function publish(): bool
    {
        return $this->update([
            'status'       => 'published',
            'published_at' => $this->published_at ?? now(),
        ]);
    }

    public function archive(): bool
    {
        return $this->update(['status' => 'archived']);
    }
}
