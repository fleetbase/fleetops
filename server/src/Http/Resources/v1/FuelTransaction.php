<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;

class FuelTransaction extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'fuel_provider_connection_uuid' => $this->when(Http::isInternalRequest(), $this->fuel_provider_connection_uuid),
            'fuel_report_uuid'              => $this->when(Http::isInternalRequest(), $this->fuel_report_uuid),
            'vehicle_uuid'                  => $this->when(Http::isInternalRequest(), $this->vehicle_uuid),
            'driver_uuid'                   => $this->when(Http::isInternalRequest(), $this->driver_uuid),
            'order_uuid'                    => $this->when(Http::isInternalRequest(), $this->order_uuid),
            'connection'                    => $this->whenLoaded('connection', fn () => $this->resolveLoadedRelation($this->connection)),
            'fuel_report'                   => $this->whenLoaded('fuelReport', fn () => $this->resolveLoadedRelation($this->fuelReport)),
            'vehicle'                       => $this->whenLoaded('vehicle', fn () => $this->resolveLoadedRelation($this->vehicle)),
            'driver'                        => $this->whenLoaded('driver', fn () => $this->resolveLoadedRelation($this->driver)),
            'order'                         => $this->whenLoaded('order', fn () => $this->resolveLoadedRelation($this->order)),
            'provider'                      => $this->provider,
            'provider_transaction_id'       => $this->provider_transaction_id,
            'provider_vehicle_id'           => $this->provider_vehicle_id,
            'vehicle_card_id'               => $this->vehicle_card_id,
            'internal_number'               => $this->internal_number,
            'structure_number'              => $this->structure_number,
            'plate_number'                  => $this->plate_number,
            'vin'                           => $this->vin,
            'serial_number'                 => $this->serial_number,
            'call_sign'                     => $this->call_sign,
            'trip_number'                   => $this->trip_number,
            'station_name'                  => $this->station_name,
            'station_latitude'              => $this->station_latitude,
            'station_longitude'             => $this->station_longitude,
            'station_location'              => $this->station_location,
            'transaction_at'                => $this->transaction_at,
            'volume'                        => $this->volume,
            'metric_unit'                   => $this->metric_unit,
            'amount'                        => $this->amount,
            'currency'                      => $this->currency,
            'odometer'                      => $this->odometer,
            'sync_status'                   => $this->sync_status,
            'matched_at'                    => $this->matched_at,
            'vehicle_name'                  => $this->vehicle_name,
            'driver_name'                   => $this->driver_name,
            'fuel_report_id'                => $this->fuel_report_id,
            'normalized_payload'            => $this->when(Http::isInternalRequest(), $this->normalized_payload),
            'raw_payload'                   => $this->when(Http::isInternalRequest(), $this->raw_payload),
            'meta'                          => $this->when(Http::isInternalRequest(), $this->meta),
            'updated_at'                    => $this->updated_at,
            'created_at'                    => $this->created_at,
        ];
    }

    protected function resolveLoadedRelation($model)
    {
        if (!$model) {
            return null;
        }

        return Http::isInternalRequest() ? Resolve::httpResourceForModel($model) : $model->public_id;
    }
}
