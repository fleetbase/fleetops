<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\Models\Category;
use Fleetbase\Models\CustomField;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a form's structure — groups of typed fields — from the shape the
 * console builder holds it in, and converts the first cut's checklist into
 * that structure.
 *
 * The console builder lets a form be laid out before the form record exists,
 * so the whole draft arrives with the first save and has to be written in one
 * go: groups become platform categories owned by the form, fields become
 * platform custom fields whose subject is the form. Rows are matched on their
 * uuid so a second post updates rather than duplicates; rows the post no
 * longer mentions are pruned only when asked, because a partial post is the
 * common case and losing fields is not.
 */
class InspectionFormSync
{
    /** The group columns the builder is allowed to write. */
    public const GROUP_COLUMNS = ['name', 'description', 'meta', 'order', 'icon', 'icon_color', 'translations', 'tags'];

    /** The field columns the builder is allowed to write. */
    public const FIELD_COLUMNS = ['name', 'label', 'type', 'component', 'options', 'required', 'editable', 'default_value', 'validation_rules', 'meta', 'description', 'help_text', 'order', 'for'];

    /**
     * @param InspectionForm $form         the form the structure belongs to
     * @param array          $draft        groups, each with its fields under `fields` or `customFields`
     * @param bool           $pruneMissing drop groups and fields the draft no longer lists
     * @param string         $primaryKey   the key rows are matched on, `uuid` or `id`
     *
     * @return array{groups: Category[], fields: CustomField[]}
     */
    public static function sync(InspectionForm $form, array $draft, bool $pruneMissing = false, string $primaryKey = 'uuid'): array
    {
        $existingGroups = Category::query()
            ->where(['owner_uuid' => $form->uuid, 'for' => InspectionForm::GROUP_FOR])
            ->get()
            ->keyBy('uuid');
        $existingFields = CustomField::query()
            ->where('subject_uuid', $form->uuid)
            ->get()
            ->keyBy('uuid');

        $keptGroups = [];
        $keptFields = [];
        $now        = now();

        foreach (array_values($draft) as $groupIndex => $groupData) {
            $groupUuid  = Arr::get($groupData, $primaryKey);
            $group      = $groupUuid ? $existingGroups->get($groupUuid) : null;
            $groupUuid  = $group?->uuid ?? ($groupUuid ?: (string) Str::uuid());
            $groupAttrs = static::normalizeRow(Arr::only($groupData, static::GROUP_COLUMNS), $groupIndex);

            if ($group) {
                DB::table('categories')->where('uuid', $groupUuid)->update(array_merge($groupAttrs, ['updated_at' => $now]));
            } else {
                DB::table('categories')->insert(array_merge($groupAttrs, [
                    'uuid'         => $groupUuid,
                    'public_id'    => 'category_' . Str::lower(Str::random(14)),
                    'company_uuid' => $form->company_uuid,
                    'owner_uuid'   => $form->uuid,
                    'owner_type'   => $form->getMorphClass(),
                    'for'          => InspectionForm::GROUP_FOR,
                    'slug'         => Str::slug((string) ($groupAttrs['name'] ?? 'group')),
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]));
            }

            $keptGroups[] = $groupUuid;

            $fields = Arr::get($groupData, 'fields', Arr::get($groupData, 'customFields', []));
            foreach (array_values(is_array($fields) ? $fields : []) as $fieldIndex => $fieldData) {
                $fieldUuid  = Arr::get($fieldData, $primaryKey);
                $field      = $fieldUuid ? $existingFields->get($fieldUuid) : null;
                $fieldUuid  = $field?->uuid ?? ($fieldUuid ?: (string) Str::uuid());
                $fieldAttrs = static::normalizeField(Arr::only($fieldData, static::FIELD_COLUMNS), $fieldIndex);

                if ($field) {
                    DB::table('custom_fields')->where('uuid', $fieldUuid)->update(array_merge($fieldAttrs, ['category_uuid' => $groupUuid, 'updated_at' => $now]));
                } else {
                    DB::table('custom_fields')->insert(array_merge($fieldAttrs, [
                        'uuid'          => $fieldUuid,
                        'company_uuid'  => $form->company_uuid,
                        'category_uuid' => $groupUuid,
                        'subject_uuid'  => $form->uuid,
                        'subject_type'  => $form->getMorphClass(),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]));
                }

                $keptFields[] = $fieldUuid;
            }
        }

        if ($pruneMissing) {
            static::prune($form, $existingGroups, $keptGroups, $existingFields, $keptFields);
        }

        $form->unsetRelation('fieldGroups');
        $form->unsetRelation('fields');
        $form->load(['fieldGroups', 'fields']);

        return ['groups' => $form->fieldGroups->all(), 'fields' => $form->fields->all()];
    }

    /**
     * Converts the first cut's `items` checklist into a "Checklist" group of
     * pass-fail fields. Skips forms that have already been built with fields,
     * and forms with nothing to convert, so it can be run any number of times.
     *
     * @return int the number of fields written
     */
    public static function convertLegacyItems(InspectionForm $form): int
    {
        $items = array_values(array_filter(is_array($form->items) ? $form->items : [], 'is_array'));
        if (empty($items) || $form->fields()->count() > 0) {
            return 0;
        }

        $fields = [];
        foreach ($items as $index => $item) {
            $label    = trim((string) ($item['label'] ?? $item['title'] ?? ''));
            $label    = $label === '' ? 'Item ' . ($index + 1) : $label;
            $fields[] = [
                'label'       => $label,
                'name'        => Str::slug((string) ($item['key'] ?? $label)),
                'type'        => 'pass-fail',
                'required'    => (bool) ($item['required'] ?? true),
                'description' => $item['description'] ?? null,
                'order'       => $index + 1,
                'meta'        => [
                    'severity'                => $item['severity'] ?? 'medium',
                    'category'                => $item['category'] ?? null,
                    'require_photo_on_fail'   => false,
                    'require_comment_on_fail' => false,
                    'unsafe_on_fail'          => ($item['severity'] ?? null) === 'critical',
                ],
            ];
        }

        $synced = static::sync($form, [[
            'name'   => 'Checklist',
            'order'  => 1,
            'meta'   => ['grid_size' => 1, 'converted_from_items' => true],
            'fields' => $fields,
        ]]);

        return count($synced['fields']);
    }

    /** Group attributes as the categories table stores them. */
    protected static function normalizeRow(array $attributes, int $index): array
    {
        if (!array_key_exists('order', $attributes) || $attributes['order'] === null) {
            $attributes['order'] = $index + 1;
        }

        foreach ($attributes as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $attributes[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $attributes;
    }

    /** Field attributes as the custom_fields table stores them. */
    protected static function normalizeField(array $attributes, int $index): array
    {
        $label = trim((string) ($attributes['label'] ?? ''));
        if ($label === '') {
            $label = 'Untitled field';
        }

        $attributes['label'] = $label;
        $attributes['name']  = Str::slug((string) (($attributes['name'] ?? '') ?: $label));
        $attributes['type']  = in_array($attributes['type'] ?? null, InspectionForm::FIELD_TYPES, true) ? $attributes['type'] : 'input';
        $attributes['for']   = InspectionForm::FIELD_FOR;

        if (empty($attributes['component'])) {
            $attributes['component'] = static::componentFor($attributes['type']);
        }

        foreach (['required', 'editable'] as $flag) {
            $attributes[$flag] = (int) filter_var($attributes[$flag] ?? ($flag === 'editable'), FILTER_VALIDATE_BOOLEAN);
        }

        return static::normalizeRow($attributes, $index);
    }

    /**
     * The console component that edits a field type. The platform's own
     * types keep the platform's components; the inspection-only types are
     * rendered by FleetOps and named after themselves.
     */
    public static function componentFor(string $type): string
    {
        return $type === 'radio-button' ? 'radio-button-select' : $type;
    }

    protected static function prune(InspectionForm $form, Collection $existingGroups, array $keptGroups, Collection $existingFields, array $keptFields): void
    {
        $groupsToDelete = $existingGroups->keys()->diff($keptGroups)->values();
        if ($groupsToDelete->isNotEmpty()) {
            CustomField::query()->where('subject_uuid', $form->uuid)->whereIn('category_uuid', $groupsToDelete)->delete();
            Category::query()->whereIn('uuid', $groupsToDelete)->delete();
        }

        $fieldsToDelete = $existingFields->keys()->diff($keptFields)->values();
        if ($fieldsToDelete->isNotEmpty()) {
            CustomField::query()->whereIn('uuid', $fieldsToDelete)->delete();
        }
    }
}
