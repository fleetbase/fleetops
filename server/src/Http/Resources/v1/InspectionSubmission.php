<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\InspectionFileStore;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\User;
use Fleetbase\Models\CustomFieldValue;
use Fleetbase\Models\File;
use Fleetbase\Support\Http;

class InspectionSubmission extends FleetbaseResource
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
        $data = $this->withCustomFields([
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'         => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'inspection_form_uuid' => $this->when(Http::isInternalRequest(), $this->inspection_form_uuid),
            'vehicle_uuid'         => $this->when(Http::isInternalRequest(), $this->vehicle_uuid),
            'driver_uuid'          => $this->when(Http::isInternalRequest(), $this->driver_uuid),
            'submitted_by_uuid'    => $this->when(Http::isInternalRequest(), $this->submitted_by_uuid),
            'issue_uuid'           => $this->when(Http::isInternalRequest(), $this->issue_uuid),
            'work_order_uuid'      => $this->when(Http::isInternalRequest(), $this->work_order_uuid),
            'form'                 => $this->whenLoaded('form', fn () => new InspectionForm($this->form)),
            'vehicle'              => $this->whenLoaded('vehicle', fn () => new Vehicle($this->vehicle)),
            'driver'               => $this->whenLoaded('driver', fn () => new Driver($this->driver)),
            'submitted_by'         => $this->whenLoaded('submittedBy', fn () => new User($this->submittedBy)),
            'issue'                => $this->whenLoaded('issue', fn () => new Issue($this->issue)),
            'work_order'           => $this->whenLoaded('workOrder', fn () => new WorkOrder($this->workOrder)),
            'item_results'         => InspectionItemResult::collection($this->whenLoaded('itemResults')),
            'type'                 => $this->type,
            'status'               => $this->status,
            'result'               => $this->result,
            'source'               => $this->source,
            'odometer'             => $this->odometer,
            'engine_hours'         => $this->engine_hours,
            'total_items'          => $this->total_items,
            'failed_items'         => $this->failed_items,
            'location'             => data_get($this, 'location', (object) []),
            'signature'            => data_get($this, 'signature', (object) []),
            'attachments'          => data_get($this, 'attachments', []),
            'meta'                 => data_get($this, 'meta', Utils::createObject()),
            'form_name'            => $this->form_name,
            'vehicle_name'         => $this->vehicle_name,
            'driver_name'          => $this->driver_name,
            'has_failures'         => $this->has_failures,
            'started_at'           => $this->started_at,
            'submitted_at'         => $this->submitted_at,
            'resolved_at'          => $this->resolved_at,
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ]);

        // The platform's own `withCustomFields` puts the raw value models
        // under `custom_field_values` for the console. What both consoles and
        // the app want is the field's identity beside its answer, with file
        // references resolved, so that projection replaces it.
        $data['custom_field_values'] = $this->projectCustomFieldValues();
        $data['files']               = $this->projectFiles();

        return $data;
    }

    /**
     * The answers, as the app and the console read them: which field, what it
     * is called, its type, and the value with every `file:<uuid>` resolved to
     * something fetchable.
     */
    protected function projectCustomFieldValues(): array
    {
        // `withCustomFields()` has already loaded the values and the fields
        // they answer, so there is nothing to guard against here.
        $internal = Http::isInternalRequest();

        return collect($this->resource?->customFieldValues)->map(function (CustomFieldValue $value) use ($internal) {
            $field = $value->customField;
            $row   = [
                'custom_field' => $value->custom_field_uuid,
                'name'         => $field?->name,
                'label'        => $field?->label ?? $value->custom_field_label,
                'type'         => $field?->type ?? $value->value_type,
                'value_type'   => $value->value_type,
                'value'        => static::projectValue($value),
            ];

            if ($internal) {
                $row['uuid']          = $value->uuid;
                $row['category_uuid'] = $field?->category_uuid;
                $row['order']         = $field?->order === null ? null : (int) $field->order;
                $row['meta']          = is_array($field?->meta) && !empty($field->meta) ? $field->meta : (object) [];
            }

            return $row;
        })->values()->all();
    }

    /**
     * One stored value, with file references resolved. A pass-fail answer
     * carries its photos inside it, so those are resolved too.
     */
    protected static function projectValue(CustomFieldValue $value): mixed
    {
        $raw = $value->getRawOriginal('value');

        if (in_array($value->value_type, ['object', 'array'], true)) {
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                return $decoded;
            }

            if (isset($decoded['photos']) && is_array($decoded['photos'])) {
                $decoded['photos'] = array_values(array_map(fn ($photo) => InspectionFileStore::project($photo), $decoded['photos']));
            }

            return $decoded;
        }

        // A value column is a string, so a meter reading comes back as one.
        // The app compares and charts these; hand it the number it wrote.
        if ($value->value_type === 'number') {
            return is_numeric($raw) ? $raw + 0 : $raw;
        }

        if ($value->value_type === 'boolean') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return InspectionFileStore::project($raw);
    }

    /** The photos and signatures filed with the inspection. */
    protected function projectFiles(): array
    {
        if (!$this->resource || !$this->resource->relationLoaded('files')) {
            return [];
        }

        return $this->resource->files->map(fn (File $file) => [
            'id'                => $file->public_id ?? $file->uuid,
            'uuid'              => $file->uuid,
            'url'               => $file->url,
            'original_filename' => $file->original_filename,
            'content_type'      => $file->content_type,
            'type'              => $file->type,
            'caption'           => $file->caption,
            'created_at'        => $file->created_at,
        ])->values()->all();
    }
}
