<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Lightweight Vehicle resource for index views.
 * Only includes essential identification and display information.
 */
class Vehicle extends FleetbaseResource
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
            'id'               => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'             => $this->when($isInternal, $this->uuid),
            'public_id'        => $this->when($isInternal, $this->public_id),
            'company_uuid'     => $this->when($isInternal, $this->company_uuid),
            'vendor_uuid'      => $this->when($isInternal, $this->vendor_uuid),
            'photo_uuid'       => $this->when($isInternal, $this->photo_uuid),
            'internal_id'      => $this->internal_id,
            'display_name'     => $this->display_name,
            'driver_name'      => $this->driver_name,
            'plate_number'     => $this->plate_number,
            'serial_number'    => $this->serial_number,
            'fuel_card_number' => $this->fuel_card_number,
            'vin'              => $this->vin,
            'make'             => $this->make,
            'model'            => $this->model,
            'year'             => $this->year,
            'photo_url'        => $this->photo_url,
            'status'           => $this->status,
            'location'         => Utils::castPoint($this->location),
            'heading'          => (int) data_get($this, 'heading', 0),
            'altitude'         => (int) data_get($this, 'altitude', 0),
            'speed'            => (int) data_get($this, 'speed', 0),
            'online'           => (bool) data_get($this, 'online', false),

            // Meta flag to indicate this is an index resource
            'meta'            => [
                '_index_resource'          => true,
                'current_order_reference'  => $this->currentOrderReference(),
                'location_coordinates'     => $this->locationCoordinates(),
                'speed_label'              => $this->speedLabel(),
                'heading_label'            => $this->headingLabel(),
                'status_label'             => $this->statusLabel(),
            ],
        ];
    }

    protected function currentOrderReference(): ?string
    {
        $this->loadMissing('driver.currentOrder');
        $order = data_get($this, 'driver.currentOrder') ?? $this->currentPositionOrder() ?? $this->currentVehicleOrder();

        return data_get($order, 'tracking') ?? data_get($order, 'public_id');
    }

    protected function locationCoordinates(): ?string
    {
        $location = Utils::castPoint($this->location);

        return $location ? $this->formatCoordinate($location->getLat()) . ' ' . $this->formatCoordinate($location->getLng()) : null;
    }

    protected function speedLabel(): string
    {
        $speed = data_get($this->lastKnownPosition(), 'speed', data_get($this, 'speed'));

        return is_numeric($speed) ? ((int) $speed) . ' km/h' : '-';
    }

    protected function headingLabel(): string
    {
        $heading = data_get($this->lastKnownPosition(), 'heading', data_get($this, 'heading'));

        return is_numeric($heading) ? ((int) $heading) . ' deg' : '-';
    }

    protected function statusLabel(): ?string
    {
        return $this->status ? str($this->status)->replace(['_', '-'], ' ')->headline()->toString() : null;
    }

    protected function currentPositionOrder(): ?Order
    {
        $orderUuid = data_get($this->lastKnownPosition(), 'order_uuid');

        return $orderUuid ? Order::where('uuid', $orderUuid)->first() : null;
    }

    protected function currentVehicleOrder(): ?Order
    {
        return Order::where('vehicle_assigned_uuid', $this->uuid)
            ->whereNotIn('status', ['completed', 'canceled', 'cancelled'])
            ->latest()
            ->first();
    }

    protected function lastKnownPosition()
    {
        if (!$this->resource->relationLoaded('last_known_position')) {
            $this->resource->setRelation('last_known_position', $this->getLastKnownPosition());
        }

        return $this->resource->getRelation('last_known_position');
    }

    protected function formatCoordinate(float $coordinate): string
    {
        return (string) round($coordinate, 4);
    }
}
