<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\File;
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
            'order_uuid'                  => $this->when(Http::isInternalRequest(), $this->order_uuid ?? data_get($this, 'meta.order_uuid')),
            'assigned_to_uuid'            => $this->when(Http::isInternalRequest(), $this->assigned_to_uuid),
            'reported_by_uuid'            => $this->when(Http::isInternalRequest(), $this->reported_by_uuid),
            'assignee'                    => $this->whenLoaded('assignee', fn () => new User($this->assignee)),
            'reporter'                    => $this->whenLoaded('reporter', fn () => new User($this->reporter)),
            'vehicle'                     => $this->whenLoaded('vehicle', fn () => new Vehicle($this->vehicle)),
            'driver'                      => $this->whenLoaded('driver', fn () => new Driver($this->driver)),
            'order'                       => $this->when(Http::isInternalRequest(), fn () => $this->resolveLinkedOrder()),
            // Public callers get the order the issue is about as its public id
            // and tracking number, not as a nested order.
            'order_id'                    => $this->when(Http::isPublicRequest(), fn () => data_get($this->linkedOrder(), 'public_id')),
            'order_tracking_number'       => $this->when(Http::isPublicRequest(), fn () => data_get($this->linkedOrder(), 'trackingNumber.tracking_number')),
            'files'                       => $this->whenLoaded('files', fn () => File::collection($this->files)),
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
     * The order the issue is about, read once. Uses the loaded relation when
     * the controller loaded it, and otherwise looks the order up inside the
     * issue's own organization.
     */
    protected function linkedOrder(): ?\Fleetbase\FleetOps\Models\Order
    {
        // An issue linked before the column existed keeps its order in `meta`;
        // a loaded relation that came back empty does not rule that out.
        if ($this->relationLoaded('order') && ($this->order || !data_get($this, 'meta.order_uuid'))) {
            return $this->order;
        }

        $orderUuid = $this->order_uuid ?? data_get($this, 'meta.order_uuid');
        if (!$orderUuid) {
            return null;
        }

        $order = \Fleetbase\FleetOps\Models\Order::with(['trackingNumber'])
            ->where('uuid', $orderUuid)
            ->where('company_uuid', $this->company_uuid)
            ->first();

        $this->resource->setRelation('order', $order);

        return $order;
    }

    protected function resolveLinkedOrder()
    {
        if ($this->relationLoaded('order') && $this->order) {
            $this->order->loadMissing(['payload.pickup', 'payload.dropoff', 'trackingNumber', 'customer']);

            return new Order($this->order);
        }

        $orderUuid = $this->order_uuid ?? data_get($this, 'meta.order_uuid');
        if (!$orderUuid) {
            return null;
        }

        $order = \Fleetbase\FleetOps\Models\Order::with(['payload.pickup', 'payload.dropoff', 'trackingNumber', 'customer'])
            ->where('uuid', $orderUuid)
            ->where('company_uuid', $this->company_uuid)
            ->first();

        return $order ? new Order($order) : null;
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
