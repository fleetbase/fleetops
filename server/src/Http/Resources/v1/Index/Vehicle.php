<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

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
            'id'              => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'            => $this->when($isInternal, $this->uuid),
            'public_id'       => $this->when($isInternal, $this->public_id),
            'company_uuid'    => $this->when($isInternal, $this->company_uuid),
            'vendor_uuid'     => $this->when($isInternal, $this->vendor_uuid),
            'photo_uuid'      => $this->when($isInternal, $this->photo_uuid),
            'internal_id'     => $this->internal_id,
            'display_name'    => $this->display_name,
            'driver_name'     => $this->driver_name,
            'plate_number'    => $this->plate_number,
            'serial_number'   => $this->serial_number,
            'vin'             => $this->vin,
            'make'            => $this->make,
            'model'           => $this->model,
            'year'            => $this->year,
            'photo_url'       => $this->photo_url,
            'status'          => $this->status,
            'location'        => Utils::castPoint($this->location),
            'heading'         => (int) data_get($this, 'heading', 0),
            'altitude'        => (int) data_get($this, 'altitude', 0),
            'speed'           => (int) data_get($this, 'speed', 0),
            'online'          => (bool) data_get($this, 'online', false),

            // Meta flag to indicate this is an index resource
            'meta'            => [
                '_index_resource'          => true,
                'current_order_reference'  => $this->currentOrderReference(),
                'location_coordinates'     => $this->locationCoordinates(),
            ],
        ];
    }

    protected function currentOrderReference(): ?string
    {
        $this->loadMissing('driver.currentOrder');
        $order = data_get($this, 'driver.currentOrder');

        return data_get($order, 'tracking') ?? data_get($order, 'public_id');
    }

    protected function locationCoordinates(): ?string
    {
        $location    = Utils::castPoint($this->location);
        $coordinates = data_get($location, 'coordinates');

        return is_array($coordinates) && count($coordinates) >= 2 ? $coordinates[1] . ' ' . $coordinates[0] : null;
    }
}
