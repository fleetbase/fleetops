<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\LiveCacheService;

class PlaceObserver
{
    /**
     * Handle the Place "creating" event.
     *
     * @return void
     */
    public function creating(Place $place)
    {
        // uppercase all address attributes
        $toUppercase = ['name', 'street1', 'street2', 'city', 'province', 'postal_code', 'neighborhood', 'district', 'building', 'country'];

        // uppercase all attributes above
        foreach ($toUppercase as $attribute) {
            if (isset($place->{$attribute}) && is_string($place->{$attribute})) {
                $place->{$attribute} = strtoupper($place->{$attribute});
            }
        }
    }

    /**
     * Handle the Place "created" event.
     *
     * @return void
     */
    public function created(Place $place)
    {
        LiveCacheService::invalidate('places');
    }

    /**
     * Handle the Place "updated" event.
     *
     * @return void
     */
    public function updated(Place $place)
    {
        LiveCacheService::invalidate('places');
    }

    /**
     * Handle the Place "deleted" event.
     *
     * @return void
     */
    public function deleted(Place $place)
    {
        LiveCacheService::invalidate('places');
    }
}
