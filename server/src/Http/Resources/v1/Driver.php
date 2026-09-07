<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\FleetbaseResourceCollection;
use Fleetbase\Http\Resources\User;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        return $this->withCustomFields([
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'user_uuid'                     => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'company_uuid'                  => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'vehicle_uuid'                  => $this->when(Http::isInternalRequest(), $this->vehicle_uuid),
            'vendor_uuid'                   => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'current_job_uuid'              => $this->when(Http::isInternalRequest(), $this->current_job_uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'user'                          => $this->when(Http::isPublicRequest(), fn () => $this->user ? $this->user->public_id : null, new User($this->user)),
            'internal_id'                   => $this->internal_id,
            'company'                       => $this->when(Http::isPublicRequest(), fn () => $this->company ? $this->company->public_id : null),
            'company_name'                  => $this->when(Http::isPublicRequest(), fn () => $this->company ? $this->company->name : null),
            'name'                          => $this->name,
            'email'                         => $this->email,
            'phone'                         => $this->phone,
            'timezone'                      => data_get($this, 'user.timezone'),
            'drivers_license_number'        => $this->drivers_license_number,
            'license_expiry'                => $this->formatDateOnly($this->license_expiry),
            'photo_url'                     => $this->photo_url,
            'avatar_url'                    => $this->avatar_url,
            'avatar_value'                  => $this->when(Http::isInternalRequest(), $this->getOriginal('avatar_url')),
            'vehicle_name'                  => $this->when(Http::isInternalRequest(), $this->vehicle_name),
            'vehicle_avatar'                => $this->vehicle_avatar,
            'vendor_name'                   => $this->when(Http::isInternalRequest(), $this->vendor_name),
            'assigned_orders_count'         => $this->when(Http::isInternalRequest(), fn () => $this->assignedOrdersCount()),
            'current_order_reference'       => $this->when(Http::isInternalRequest(), fn () => $this->currentOrderReference()),
            'vehicle'                       => $this->whenLoaded('vehicle', fn () => new VehicleWithoutDriver($this->vehicle)),
            'current_job'                   => $this->whenLoaded('currentJob', fn () => new Order($this->currentJob)),
            'current_job_id'                => $this->when(Http::isInternalRequest(), data_get($this, 'currentJob.tracking')),
            'jobs'                          => $this->whenLoaded('jobs', fn () => $this->getJobs()),
            'vendor'                        => $this->whenLoaded('vendor', fn () => new Vendor($this->vendor)),
            // Public callers receive each assignment as a public id so a write
            // can be read back; the nested objects above are unchanged.
            'vehicle_id'                    => $this->when(Http::isPublicRequest(), fn () => $this->vehicle_id),
            'vendor_id'                     => $this->when(Http::isPublicRequest(), fn () => $this->vendor_id),
            'job_id'                        => $this->when(Http::isPublicRequest(), fn () => data_get($this, 'currentJob.public_id')),
            'fleets'                        => $this->whenLoaded('fleets', fn () => Fleet::collection($this->fleets()->without('drivers')->get())),
            'current_shift'                 => $this->whenLoaded('currentShift', fn () => $this->currentShift),
            'location'                      => $this->wasRecentlyCreated ? new Point(0, 0) : Utils::castPoint($this->location),
            'heading'                       => (int) data_get($this, 'heading', 0),
            'bearing'                       => data_get($this, 'bearing'),
            'altitude'                      => (int) data_get($this, 'altitude', 0),
            'speed'                         => (int) data_get($this, 'speed', 0),
            'country'                       => data_get($this, 'country'),
            'currency'                      => data_get($this, 'currency', Utils::getCurrenyFromCountryCode($this->country)),
            'city'                          => data_get($this, 'city', Utils::getCapitalCityFromCountryCode($this->country)),
            'online'                        => data_get($this, 'online', false),
            'current_status'                => data_get($this, 'current_status'),
            'status'                        => $this->status,
            'token'                         => $this->token,
            // Orchestrator constraints
            'skills'                        => data_get($this, 'skills'),
            'max_travel_time'               => $this->max_travel_time,
            'max_distance'                  => $this->max_distance,
            'time_window_start'             => $this->time_window_start,
            'time_window_end'               => $this->time_window_end,
            'meta'                          => data_get($this, 'meta', Utils::createObject()),
            'updated_at'                    => $this->updated_at,
            'created_at'                    => $this->created_at,
        ]);
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

    public function getJobs(): AnonymousResourceCollection|FleetbaseResourceCollection
    {
        return Order::collection(
            $this->jobs()->with(
                [
                    'driverAssigned' => function ($query) {
                        $query->without('jobs');
                    },
                ]
            )->get()
        );
    }

    protected function formatDateOnly($date): ?string
    {
        if (!$date) {
            return null;
        }

        return method_exists($date, 'toDateString') ? $date->toDateString() : (string) $date;
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
            'license_expiry'         => $this->formatDateOnly($this->license_expiry),
            'vehicle'                => data_get($this, 'vehicle.public_id'),
            'current_job'            => data_get($this, 'currentJob.public_id'),
            'vendor'                 => data_get($this, 'vendor.public_id'),
            'location'               => Utils::castPoint($this->location),
            'heading'                => (int) data_get($this, 'heading', 0),
            'altitude'               => (int) data_get($this, 'altitude', 0),
            'speed'                  => (int) data_get($this, 'speed', 0),
            'country'                => data_get($this, 'country'),
            'currency'               => data_get($this, 'currency', Utils::getCurrenyFromCountryCode($this->country)),
            'city'                   => data_get($this, 'city', Utils::getCapitalCityFromCountryCode($this->country)),
            'online'                 => data_get($this, 'online', false),
            'current_status'         => data_get($this, 'current_status'),
            'status'                 => $this->status,
            'skills'                 => data_get($this, 'skills'),
            'max_travel_time'        => $this->max_travel_time,
            'max_distance'           => $this->max_distance,
            'time_window_start'      => $this->time_window_start,
            'time_window_end'        => $this->time_window_end,
            'meta'                   => data_get($this, 'meta', Utils::createObject()),
            'updated_at'             => $this->updated_at,
            'created_at'             => $this->created_at,
        ];
    }
}
