<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FuelProviderTransactionImported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public FuelProviderTransaction $transaction)
    {
    }
}
