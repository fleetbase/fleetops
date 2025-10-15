<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\User;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;

class Issue extends FleetbaseResource
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
            'id'                          => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                        => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                   => $this->when(Http::isInternalRequest(), $this->public_id),
            'driver_uuid'                 => $this->when(Http::isInternalRequest(), $this->driver_uuid),
            'company_uuid'                => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'vehicle_uuid'                => $this->when(Http::isInternalRequest(), $this->vehicle_uuid),
            'assigned_to_uuid'            => $this->when(Http::isInternalRequest(), $this->assigned_to_uuid),
            'reported_by_uuid'            => $this->when(Http::isInternalRequest(), $this->reported_by_uuid),
            'assignee'                    => $this->whenLoaded('assignee', fn () => new User($this->assignee)),
            'reporter'                    => $this->whenLoaded('reporter', fn () => new User($this->reporter)),
            'vehicle'                     => $this->whenLoaded('vehicle', fn () => new Vehicle($this->vehicle)),
            'driver'                      => $this->whenLoaded('driver', fn () => new Driver($this->driver)),
            'driver_name'                 => $this->driver_name,
            'vehicle_name'                => $this->vehicle_name,
            'vehicle_id'                  => $this->vehicle_id,
            'assignee_name'               => $this->assignee_name,
            'assignee_id'                 => $this->assignee_id,
            'reporter_name'               => $this->reporter_name,
            'reporter_id'                 => $this->reporter_id,
            'issue_id'                    => $this->issue_id,
            'title'                       => $this->title,
            'report'                      => $this->report,
            'priority'                    => $this->priority,
            'meta'                        => data_get($this, 'meta', Utils::createObject()),
            'type'                        => $this->type,
            'category'                    => $this->category,
            'tags'                        => $this->tags ?? [],
            'status'                      => $this->status,
            'location'                    => data_get($this, 'location', new Point(0, 0)),
            'resolved_at'                 => $this->resolved_at,
            'updated_at'                  => $this->updated_at,
            'created_at'                  => $this->created_at,
        ]);
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'                 => $this->public_id,
            'reporter'           => data_get($this, 'reportedBy.public_id'),
            'assignee'           => data_get($this, 'assignedTo.public_id'),
            'driver'             => data_get($this, 'driver.public_id'),
            'vehicle'            => data_get($this, 'vehicle.public_id'),
            'issue_id'           => $this->issue_id,
            'report'             => $this->report,
            'priority'           => $this->priority,
            'meta'               => data_get($this, 'meta', Utils::createObject()),
            'type'               => $this->type,
            'category'           => $this->category,
            'status'             => $this->status,
            'location'           => data_get($this, 'location', new Point(0, 0)),
            'resolved_at'        => $this->resolved_at,
            'updated_at'         => $this->updated_at,
            'created_at'         => $this->created_at,
        ];
    }
}
