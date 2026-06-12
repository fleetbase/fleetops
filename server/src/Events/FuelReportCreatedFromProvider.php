<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FuelReportCreatedFromProvider
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public FuelProviderTransaction $transaction, public FuelReport $fuelReport)
    {
    }
}
