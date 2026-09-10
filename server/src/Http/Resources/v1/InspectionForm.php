<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\InspectionFormSync;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\Category;
use Fleetbase\Models\CustomField;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class InspectionForm extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $internal = Http::isInternalRequest();

        return $this->withCustomFields([
            'id'             => $this->when($internal, $this->id, $this->public_id),
            'uuid'           => $this->when($internal, $this->uuid),
            'public_id'      => $this->when($internal, $this->public_id),
            'company_uuid'   => $this->when($internal, $this->company_uuid),
            'created_by_uuid'=> $this->when($internal, $this->created_by_uuid),
            'updated_by_uuid'=> $this->when($internal, $this->updated_by_uuid),
            'subject_uuid'   => $this->when($internal, $this->subject_uuid),
            'subject_type'   => $this->when($internal, $this->subject_type ? Utils::toEmberResourceType($this->subject_type) : null),
            'subject'        => $this->whenLoaded('subject', fn () => $this->setSubjectType($this->transformMorphResource($this->subject))),
            'name'           => $this->name,
            'description'    => $this->description,
            'type'           => $this->type,
            'status'         => $this->status,
            'items'          => data_get($this, 'items', []),
            'grouped_fields' => array_map(fn (Category $group) => static::groupToArray($group, $internal), $this->grouped_fields),
            'field_groups'   => $this->whenLoaded('fieldGroups', fn () => $this->fieldGroups->map(fn (Category $group) => static::groupToArray($group, $internal, false))->values()->all()),
            'fields'         => $this->whenLoaded('fields', fn () => $this->fields->map(fn (CustomField $field) => static::fieldToArray($field, $internal))->values()->all()),
            'settings'       => data_get($this, 'settings', (object) []),
            'meta'           => data_get($this, 'meta', (object) []),
            'subject_name'   => $this->subject_name,
            'item_count'     => $this->item_count,
            'is_published'   => $this->is_published,
            'published_at'   => $this->published_at,
            'updated_at'     => $this->updated_at,
            'created_at'     => $this->created_at,
        ]);
    }

    /**
     * A field group as the API answers it. The console gets the identifiers it
     * addresses the category by; the driver gets what it renders.
     */
    public static function groupToArray(Category $group, bool $internal, bool $withFields = true): array
    {
        $data = [
            'id'          => $internal ? $group->uuid : ($group->public_id ?? $group->uuid),
            'uuid'        => $group->uuid,
            'name'        => $group->name,
            'description' => $group->description,
            'order'       => $group->order === null ? null : (int) $group->order,
            'meta'        => is_array($group->meta) && !empty($group->meta) ? $group->meta : (object) [],
        ];

        if ($internal) {
            $data['public_id']    = $group->public_id;
            $data['company_uuid'] = $group->company_uuid;
            $data['owner_uuid']   = $group->owner_uuid;
            $data['owner_type']   = $group->owner_type;
            $data['for']          = $group->for;
        }

        if ($withFields) {
            $fields         = $group->relationLoaded('fields') ? $group->getRelation('fields') : collect();
            $data['fields'] = collect($fields)->map(fn (CustomField $field) => static::fieldToArray($field, $internal))->values()->all();
        }

        return $data;
    }

    /**
     * A field as the API answers it. A custom field has no public id, so the
     * uuid is the id on both sides; it is what a submit names the field by.
     */
    public static function fieldToArray(CustomField $field, bool $internal): array
    {
        $data = [
            'id'          => $field->uuid,
            'uuid'        => $field->uuid,
            'name'        => $field->name,
            'label'       => $field->label,
            'description' => $field->description,
            'help_text'   => $field->help_text,
            'type'        => $field->type,
            // A field written straight into the table — by a seed, or by an
            // older builder — may carry no component. The type names its own.
            'component'   => $field->component ?: InspectionFormSync::componentFor((string) $field->type),
            'required'    => (bool) $field->required,
            'editable'    => $field->editable === null ? true : (bool) $field->editable,
            'options'     => is_array($field->options) ? array_values($field->options) : [],
            'order'       => $field->order === null ? null : (int) $field->order,
            'meta'        => is_array($field->meta) && !empty($field->meta) ? $field->meta : (object) [],
        ];

        if ($internal) {
            $data['company_uuid']     = $field->company_uuid;
            $data['category_uuid']    = $field->category_uuid;
            $data['subject_uuid']     = $field->subject_uuid;
            $data['subject_type']     = $field->subject_type;
            $data['for']              = $field->for;
            $data['default_value']    = $field->default_value;
            $data['validation_rules'] = $field->validation_rules;
        }

        return $data;
    }

    protected function setSubjectType(?array $resolved): ?array
    {
        if (empty($resolved)) {
            return $resolved;
        }

        $bareSlug = Str::kebab(class_basename($this->subject_type ?? ''));

        data_set($resolved, 'type', 'maintenance-subject-' . $bareSlug);
        data_set($resolved, 'subject_type', 'maintenance-subject-' . $bareSlug);

        return $resolved;
    }

    protected function transformMorphResource($model): ?array
    {
        if (!$model) {
            return null;
        }

        // Always answers: a model with no resource of its own is served by the
        // base FleetbaseResource, so there is no second fallback to keep here.
        $resourceClass = \Fleetbase\Support\Find::httpResourceForModel($model);

        return (new $resourceClass($model))->resolve();
    }
}
