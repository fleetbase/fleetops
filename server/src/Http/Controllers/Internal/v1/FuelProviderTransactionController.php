<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;

class FuelProviderTransactionController extends FleetOpsController
{
    public $resource = 'fuel_provider_transaction';

    public static function onQueryRecord($query, $request): void
    {
        $query->with(['vehicle', 'driver', 'fuelReport']);
    }
}
