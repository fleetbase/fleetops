<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\User;
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
        return $this->withCustomFields([
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
    }
}
