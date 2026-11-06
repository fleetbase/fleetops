<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\OrderOffer;
use Fleetbase\FleetOps\Support\OrderOffers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs once an offer's time is up. An offer already answered is left alone. If this job is
 * lost, `fleetops:dispatch-adhoc` closes the offer on its next run.
 */
class ExpireOrderOffer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public $tries = 1;

    public function __construct(public string $offerUuid)
    {
    }

    public function handle(): bool
    {
        $offer = OrderOffer::where('uuid', $this->offerUuid)->first();

        return $offer ? OrderOffers::expire($offer) : false;
    }
}
