<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class InspectionItemResult extends FleetbaseResource
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
        return [
            'id'                         => $this->when(Http::isInternalRequest(), $this->id, $this->uuid),
            'uuid'                       => $this->when(Http::isInternalRequest(), $this->uuid),
            'company_uuid'               => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'inspection_submission_uuid' => $this->when(Http::isInternalRequest(), $this->inspection_submission_uuid),
            'issue_uuid'                 => $this->when(Http::isInternalRequest(), $this->issue_uuid),
            'work_order_uuid'            => $this->when(Http::isInternalRequest(), $this->work_order_uuid),
            'item_key'                   => $this->item_key,
            'label'                      => $this->label,
            'category'                   => $this->category,
            'status'                     => $this->status,
            'severity'                   => $this->severity,
            'passed'                     => $this->passed,
            'comments'                   => $this->comments,
            'photos'                     => data_get($this, 'photos', []),
            'meta'                       => $this->projectMeta(),
            'submission_id'              => $this->submission_id,
            'updated_at'                 => $this->updated_at,
            'created_at'                 => $this->created_at,
        ];
    }

    /**
     * `custom_field_uuid` links a result back to the field it mirrors, which is
     * the console's business. Outside, `item_key` already names the field, so
     * the uuid is not something a consumer should have to see.
     */
    protected function projectMeta(): mixed
    {
        $meta = data_get($this, 'meta');
        if (!is_array($meta) || empty($meta)) {
            return Utils::createObject();
        }

        if (!Http::isInternalRequest()) {
            unset($meta['custom_field_uuid']);
        }

        return empty($meta) ? Utils::createObject() : $meta;
    }
}
