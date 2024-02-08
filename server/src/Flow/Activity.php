<?php 

namespace Fleetbase\FleetOps\Flow;

use Fleetbase\Models\Model;

class Activity {
    protected string $status;
    protected string $code;
    protected string $details;
    protected Model $owner;
}