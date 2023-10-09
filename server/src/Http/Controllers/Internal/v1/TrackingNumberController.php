<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;

use Fleetbase\Http\Controllers\FleetbaseController;

class TrackingNumberController extends FleetOpsController
{
    /**
     * The resource to query
     *
     * @var string
     */
    public $resource = 'tracking_number';
}
