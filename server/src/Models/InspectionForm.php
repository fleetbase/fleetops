<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\Category;
use Fleetbase\Models\CustomField;
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
use Illuminate\Support\Collection;
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

    /**
     * What a field built for an inspection form is filed under, so the
     * platform's custom-field listings can tell an inspection field from a
     * field added to the form record itself.
     */
    public const FIELD_FOR = 'fleetops_inspection_form';

    /** The category kind a form's field groups are stored as. */
    public const GROUP_FOR = 'custom_field_group';

    /** The field types an inspection form may be built from. */
    public const FIELD_TYPES = [
        'pass-fail',
        'input',
        'textarea',
        'number',
        'select',
        'radio-button',
        'boolean',
        'date-picker',
        'date-time-input',
        'file-upload',
        'signature',
    ];

    protected $table             = 'inspection_forms';
    protected $publicIdType      = 'inspection_form';
    protected $searchableColumns = ['name', 'description', 'type', 'public_id'];
    protected $filterParams      = ['status', 'type', 'subject_type', 'subject_uuid'];

    protected $fillable = [
        'company_uuid',
        'name',
        'description',
        'type',
        'status',
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
    protected $with    = ['subject'];

    protected static $logName         = 'inspection_form';
    protected static $logAttributes   = '*';
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

    /**
     * The groups the form's fields are laid out in: platform categories owned
     * by the form, in the order the builder put them.
     */
    public function fieldGroups(): HasMany
    {
        return $this->hasMany(Category::class, 'owner_uuid', 'uuid')
            ->where('for', static::GROUP_FOR)
            ->orderByRaw('COALESCE(`order`, 999999), created_at');
    }

    /**
     * The fields a driver fills in: platform custom fields whose subject is
     * the form, filed under the inspection kind so a field attached to the
     * form record by the console's generic custom-field panel is not one.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(CustomField::class, 'subject_uuid', 'uuid')
            ->where('for', static::FIELD_FOR)
            ->orderByRaw('COALESCE(`order`, 999999), created_at');
    }

    /**
     * The form as the driver sees it: every group with its fields inside,
     * both sorted by `order` then creation, with the fields that belong to
     * no group gathered into an "Ungrouped" tail.
     *
     * @return Category[]
     */
    public function getGroupedFieldsAttribute(): array
    {
        $this->loadMissing(['fieldGroups', 'fields']);

        $fieldsByGroup = $this->fields->groupBy(fn (CustomField $field) => $field->category_uuid ?: '_ungrouped');

        $grouped = static::sortByOrder($this->fieldGroups)->map(function (Category $group) use ($fieldsByGroup) {
            $group->setRelation('fields', static::sortByOrder($fieldsByGroup->get($group->uuid, collect())));

            return $group;
        });

        if ($fieldsByGroup->has('_ungrouped')) {
            $ungrouped = new Category([
                'name'       => 'Ungrouped',
                'for'        => static::GROUP_FOR,
                'owner_uuid' => $this->uuid,
            ]);
            $ungrouped->exists = false;
            $ungrouped->setRelation('fields', static::sortByOrder($fieldsByGroup->get('_ungrouped')));
            $grouped->push($ungrouped);
        }

        return $grouped->values()->all();
    }

    /**
     * Sorts groups or fields the way the builder laid them out: an explicit
     * `order` first (lowest first), then anything without one in creation order.
     */
    public static function sortByOrder(Collection $items): Collection
    {
        return $items->sort(function ($a, $b) {
            $aOrder = $a->order === null ? null : (int) $a->order;
            $bOrder = $b->order === null ? null : (int) $b->order;

            if ($aOrder !== null && $bOrder !== null && $aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            if ($aOrder !== null && $bOrder === null) {
                return -1;
            }

            if ($aOrder === null && $bOrder !== null) {
                return 1;
            }

            return (string) ($a->created_at ?? '') <=> (string) ($b->created_at ?? '');
        })->values();
    }

    public function getSubjectNameAttribute(): ?string
    {
        return $this->subject?->name ?? $this->subject?->display_name ?? $this->subject?->public_id;
    }

    /**
     * How many things a driver answers. Fields once the form has been built
     * with them; the legacy checklist while it still is one.
     */
    public function getItemCountAttribute(): int
    {
        $fields = $this->relationLoaded('fields') ? $this->fields->count() : $this->fields()->count();
        if ($fields > 0) {
            return $fields;
        }

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
