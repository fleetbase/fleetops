<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;

/**
 * Lightweight Driver resource for index views.
 * Only includes essential identification and display information.
 */
class Driver extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        return [
            'id'                    => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'                  => $this->when($isInternal, $this->uuid),
            'public_id'             => $this->when($isInternal, $this->public_id),
            'company_uuid'          => $this->when($isInternal, $this->company_uuid),
            'user_uuid'             => $this->when($isInternal, $this->user_uuid),
            'vehicle_uuid'          => $this->when($isInternal, $this->vehicle_uuid),
            'vendor_uuid'           => $this->when($isInternal, $this->vendor_uuid),
            'current_job_uuid'      => $this->when($isInternal, $this->current_job_uuid),
            'assigned_orders_count' => $this->when($isInternal, $this->assignedOrdersCount()),
            'name'                  => $this->name,
            'vehicle_name'          => $this->when($isInternal, $this->vehicle_name),
            'email'                 => $this->email,
            'phone'                 => $this->phone,
            'photo_url'             => $this->photo_url,
            'status'                => $this->status,
            'location'              => $this->wasRecentlyCreated ? new Point(0, 0) : Utils::castPoint($this->location),
            'heading'               => (int) data_get($this, 'heading', 0),
            'altitude'              => (int) data_get($this, 'altitude', 0),
            'speed'                 => (int) data_get($this, 'speed', 0),
            'online'                => data_get($this, 'online', false),
            'meta'                  => [
                '_index_resource'         => true,
                'location_coordinates'    => $this->locationCoordinates(),
                'current_order_reference' => $this->currentOrderReference(),
                'speed_label'             => $this->speedLabel(),
                'heading_label'           => $this->headingLabel(),
                'status_label'            => $this->statusLabel(),
            ],
        ];
    }

    protected function assignedOrdersCount(): int
    {
        return $this->orders()->count();
    }

    protected function currentOrderReference(): ?string
    {
        $this->loadMissing('currentOrder');
        $order = data_get($this, 'currentOrder');

        return data_get($order, 'tracking') ?? data_get($order, 'public_id');
    }

    protected function locationCoordinates(): ?string
    {
        $location = Utils::castPoint($this->location);

        return $location ? $this->formatCoordinate($location->getLat()) . ' ' . $this->formatCoordinate($location->getLng()) : null;
    }

    protected function speedLabel(): string
    {
        $speed = data_get($this, 'speed');

        return is_numeric($speed) ? ((int) $speed) . ' km/h' : '-';
    }

    protected function headingLabel(): string
    {
        $heading = data_get($this, 'heading');

        return is_numeric($heading) ? ((int) $heading) . ' deg' : '-';
    }

    protected function statusLabel(): ?string
    {
        return $this->status ? str($this->status)->replace(['_', '-'], ' ')->headline()->toString() : null;
    }

    protected function formatCoordinate(float $coordinate): string
    {
        return (string) round($coordinate, 4);
    }
}
