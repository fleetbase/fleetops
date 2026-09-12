<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Support\InspectionFileStore;
use Fleetbase\Models\Category;
use Fleetbase\Models\CustomField;
use Fleetbase\Models\CustomFieldValue;
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
use Fleetbase\LaravelMysqlSpatial\Types\Point;
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

    protected $table             = 'inspection_submissions';
    protected $publicIdType      = 'inspection_submission';
    protected $searchableColumns = ['public_id', 'type', 'status', 'result', 'vehicle.name', 'driver.name'];
    protected $filterParams      = ['status', 'result', 'type', 'source', 'vehicle', 'driver', 'inspection_form_uuid'];

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

    /**
     * The column defaults, restored when a client sends null for them.
     *
     * An explicit null in an insert overrides the column's default rather than
     * falling back to it, and these columns are NOT NULL. The console's model
     * serialises every attribute, so it sent `total_items: null` and the insert
     * was refused before the counts could be worked out from the answers.
     */
    public const COLUMN_DEFAULTS = [
        'type'         => 'dvir',
        'status'       => 'draft',
        'total_items'  => 0,
        'failed_items' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (InspectionSubmission $submission) {
            foreach (static::COLUMN_DEFAULTS as $column => $default) {
                if ($submission->getAttribute($column) === null) {
                    $submission->setAttribute($column, $default);
                }
            }
        });
    }

    protected $appends = ['form_name', 'vehicle_name', 'driver_name', 'has_failures'];
    protected $with    = ['form', 'vehicle', 'driver'];

    protected static $logName         = 'inspection_submission';
    protected static $logAttributes   = '*';
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

    /**
     * The photos and signatures filed with the inspection: platform files
     * whose subject is the submission, however they arrived — inside the
     * driver's submit body, or dropped on the console's Photos panel.
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'subject_uuid', 'uuid')->orderBy('created_at');
    }

    /**
     * Every `pass-fail` answer, as the row the rest of the platform reads.
     *
     * Issues, work orders and the history are all built from
     * `inspection_item_results`; a form built from fields answers through
     * custom-field values instead, so each pass-fail value is mirrored into
     * a result row keyed the way the app keys it — the field's name, or its
     * uuid when it has none. Rows for pass-fail fields the submission no
     * longer answers are dropped; rows written any other way (a legacy
     * checklist, the console's results editor) are left alone.
     *
     * @return int the number of result rows derived
     */
    public function syncItemResultsFromCustomFieldValues(): int
    {
        $this->unsetRelation('customFieldValues');
        $this->load('customFieldValues.customField');

        $answered = $this->customFieldValues->filter(fn (CustomFieldValue $value) => $value->customField?->type === 'pass-fail');
        $groups   = Category::query()->whereIn('uuid', $answered->map(fn (CustomFieldValue $value) => $value->customField->category_uuid)->filter()->unique()->values())->pluck('name', 'uuid');
        $unsafe   = false;
        $kept     = [];

        foreach ($answered as $value) {
            /** @var CustomField $field */
            $field         = $value->customField;
            $answer        = is_array($value->value) ? $value->value : [];
            $notApplicable = filter_var($answer['not_applicable'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $passed        = $notApplicable || filter_var($answer['passed'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $failed        = !$passed;
            $isUnsafe      = $failed && filter_var($answer['unsafe'] ?? data_get($field->meta, 'unsafe_on_fail', false), FILTER_VALIDATE_BOOLEAN);
            $unsafe        = $unsafe || $isUnsafe;
            $itemKey       = static::itemKeyFor($field);

            InspectionItemResult::updateOrCreate([
                'inspection_submission_uuid' => $this->uuid,
                'item_key'                   => $itemKey,
            ], [
                'company_uuid' => $this->company_uuid,
                'label'        => $field->label ?? $field->name,
                'category'     => $groups->get($field->category_uuid) ?? data_get($field->meta, 'category'),
                'status'       => $notApplicable ? 'not_applicable' : ($passed ? 'passed' : 'failed'),
                'severity'     => $failed ? ($answer['severity'] ?? data_get($field->meta, 'severity')) : null,
                'passed'       => $passed,
                'comments'     => $answer['comments'] ?? null,
                'photos'       => array_values(array_filter((array) ($answer['photos'] ?? []), 'is_string')),
                'meta'         => [
                    'custom_field_uuid' => $field->uuid,
                    'not_applicable'    => $notApplicable,
                    'unsafe'            => $isUnsafe,
                ],
            ]);

            $kept[] = $itemKey;
        }

        $stale = CustomField::query()
            ->where('subject_uuid', $this->inspection_form_uuid)
            ->where('for', InspectionForm::FIELD_FOR)
            ->where('type', 'pass-fail')
            ->get()
            ->map(fn (CustomField $field) => static::itemKeyFor($field))
            ->diff($kept)
            ->values();
        if ($stale->isNotEmpty()) {
            $this->itemResults()->whereIn('item_key', $stale)->delete();
        }

        $meta = $this->meta ?? [];
        if (($meta['unsafe'] ?? null) !== $unsafe) {
            $this->update(['meta' => array_merge($meta, ['unsafe' => $unsafe])]);
        }

        InspectionFileStore::attachReferenced($this, $this->referencedFileUuids());
        $this->unsetRelation('itemResults');

        return count($kept);
    }

    /**
     * The key a pass-fail field's result row is filed under. The app derives
     * the same key (`field.name ?? field.id`), so a result the app built and
     * one the server derived name the same item.
     */
    public static function itemKeyFor(CustomField $field): string
    {
        return $field->name ?: $field->uuid;
    }

    /**
     * Every `file:<uuid>` the submission's values point at: file and
     * signature values, and the photos inside a failed pass-fail answer.
     *
     * @return string[]
     */
    public function referencedFileUuids(): array
    {
        $uuids = [];
        foreach ($this->customFieldValues as $value) {
            $raw        = $value->getRawOriginal('value');
            $candidates = is_array($value->value) ? (array) ($value->value['photos'] ?? []) : [$raw];
            foreach ($candidates as $candidate) {
                $uuid = InspectionFileStore::referencedUuid($candidate);
                if ($uuid) {
                    $uuids[] = $uuid;
                }
            }
        }

        return array_values(array_unique($uuids));
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
        $total  = $this->itemResults()->count();
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
        $issue        = Issue::create([
            'company_uuid'      => $this->company_uuid,
            'reported_by_uuid'  => $this->submitted_by_uuid,
            'vehicle_uuid'      => $this->vehicle_uuid,
            'driver_uuid'       => $this->driver_uuid,
            'type'              => 'inspection',
            'category'          => 'inspection_failed',
            'location'          => $this->failureLocation(),
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

    /**
     * Where the failure was reported, for the issue it raises.
     *
     * `issues.location` is a spatial column with no default, so an insert that
     * leaves it out is refused outright — MySQL 1364, which reached a driver
     * filing a failed inspection as a 500. The submission's own coordinates
     * come first, then the vehicle's last known position, then the driver's,
     * and an empty point when nothing is known.
     */
    public function failureLocation(): Point
    {
        // Read here rather than through Utils::getPointFromMixed(), which
        // throws when it cannot resolve a point: every source below is
        // routinely empty, and an inspection must not fail for want of one.
        foreach ([$this->location, $this->vehicle?->location, $this->driver?->location] as $candidate) {
            if ($candidate instanceof Point) {
                return $candidate;
            }

            $latitude  = data_get($candidate, 'latitude', data_get($candidate, 'lat'));
            $longitude = data_get($candidate, 'longitude', data_get($candidate, 'lng'));

            if (is_numeric($latitude) && is_numeric($longitude)) {
                return new Point((float) $latitude, (float) $longitude);
            }
        }

        return new Point(0, 0);
    }

    public function createWorkOrderFromFailures(): ?WorkOrder
    {
        if (!$this->has_failures || $this->work_order_uuid) {
            return $this->workOrder;
        }

        $failedItems = $this->failedItemResults()->get();
        $checklist   = $failedItems->map(fn (InspectionItemResult $item) => [
            'title'      => $item->label,
            'required'   => true,
            'completed'  => false,
            'source'     => 'inspection',
            'item_key'   => $item->item_key,
            'severity'   => $item->severity,
            'created_at' => now(),
        ])->values()->all();

        $workOrder = WorkOrder::create([
            'company_uuid'    => $this->company_uuid,
            'subject'         => 'Inspection repair: ' . ($this->vehicle_name ?? $this->public_id),
            'status'          => 'open',
            'priority'        => $this->highestFailureSeverity(),
            'target_type'     => $this->vehicle_uuid ? Vehicle::class : null,
            'target_uuid'     => $this->vehicle_uuid,
            'opened_at'       => now(),
            'due_at'          => now()->addDays($this->highestFailureSeverity() === 'critical' ? 1 : 7),
            'instructions'    => 'Resolve failed inspection items and record completion details.',
            'checklist'       => $checklist,
            'currency'        => $this->vehicle?->currency,
            'created_by_uuid' => $this->submitted_by_uuid,
            'meta'            => [
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
