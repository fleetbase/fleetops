<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Illuminate\Http\Request;

class PayloadController extends FleetOpsController
{
    /**
     * The resource to query
     *
     * @var string
     */
    public $resource = 'payload';
}
