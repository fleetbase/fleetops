<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Grimzy\LaravelMysqlSpatial\Types\Point;

class Driver extends FleetbaseResource
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
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'user_uuid'                     => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'company_uuid'                  => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'vehicle_uuid'                  => $this->when(Http::isInternalRequest(), $this->vehicle_uuid),
            'vendor_uuid'                   => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'current_job_uuid'              => $this->when(Http::isInternalRequest(), $this->current_job_uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'internal_id'                   => $this->internal_id,
            'name'                          => $this->name,
            'email'                         => $this->email ?? null,
            'phone'                         => $this->phone ?? null,
            'drivers_license_number'        => $this->drivers_license_number ?? null,
            'photo_url'                     => $this->photo_url ?? null,
            'vehicle_name'                  => $this->when(Http::isInternalRequest(), $this->vehicle_name),
            'vehicle_avatar'                => $this->when(Http::isInternalRequest(), $this->vehicle_avatar),
            'vendor_name'                   => $this->when(Http::isInternalRequest(), $this->vendor_name),
            'vehicle'                       => $this->whenLoaded('vehicle', new VehicleWithoutDriver($this->vehicle)),
            'current_job'                   => $this->whenLoaded('currentJob', new CurrentJob($this->currentJob)),
            'current_job_id'                => $this->when(Http::isInternalRequest(), data_get($this, 'currentJob.public_id')),
            'jobs'                          => $this->whenLoaded('jobs', CurrentJob::collection($this->jobs()->without(['driverAssigned'])->get())),
            'vendor'                        => $this->whenLoaded('vendor', new Vendor($this->vendor)),
            'fleets'                        => $this->whenLoaded('fleets', Fleet::collection($this->fleets()->without('drivers')->get())),
            'location'                      => data_get($this, 'location', new Point(0, 0)),
            'heading'                       => (int) data_get($this, 'heading', 0),
            'altitude'                      => (int) data_get($this, 'altitude', 0),
            'speed'                         => (int) data_get($this, 'speed', 0),
            'country'                       => data_get($this, 'country'),
            'currency'                      => data_get($this, 'currency', Utils::getCurrenyFromCountryCode($this->country)),
            'city'                          => data_get($this, 'city', Utils::getCapitalCityFromCountryCode($this->country)),
            'online'                        => data_get($this, 'online', false),
            'status'                        => $this->status,
            'token'                         => data_get($this, 'token'),
            'meta'                          => $this->meta,
            'updated_at'                    => $this->updated_at,
            'created_at'                    => $this->created_at,
        ];
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'                     => $this->public_id,
            'internal_id'            => $this->internal_id,
            'name'                   => $this->name,
            'email'                  => $this->email,
            'phone'                  => $this->phone,
            'photo_url'              => $this->photo_url,
            'vehicle'                => data_get($this, 'vehicle.public_id'),
            'current_job'            => data_get($this, 'currentJob.public_id'),
            'vendor'                 => data_get($this, 'vendor.public_id'),
            'location'               => data_get($this, 'location', new Point(0, 0)),
            'heading'                => (int) data_get($this, 'heading', 0),
            'altitude'               => (int) data_get($this, 'altitude', 0),
            'speed'                  => (int) data_get($this, 'speed', 0),
            'country'                => data_get($this, 'country'),
            'currency'               => data_get($this, 'currency', Utils::getCurrenyFromCountryCode($this->country)),
            'city'                   => data_get($this, 'city', Utils::getCapitalCityFromCountryCode($this->country)),
            'online'                 => data_get($this, 'online', false),
            'status'                 => $this->status,
            'meta'                   => $this->meta,
            'updated_at'             => $this->updated_at,
            'created_at'             => $this->created_at,
        ];
    }
}
