<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\FleetbaseController;

class FleetOpsController extends FleetbaseController
{
    /**
     * The package namespace used to resolve from.
     */
    public string $namespace = '\\Fleetbase\\FleetOps';
}
