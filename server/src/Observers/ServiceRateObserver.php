<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Support\Utils;

class ServiceRateObserver
{
    /**
     * Handle the ServiceRate "created" event.
     *
     * @return void
     */
    public function created(ServiceRate $serviceRate)
    {
        $serviceRateFees       = request()->input('serviceRate.rate_fees');
        $serviceRateParcelFees = request()->input('serviceRate.parcel_fees');

        if ($serviceRate->isFixedMeter() || $serviceRate->isPerDrop()) {
            $serviceRate->setServiceRateFees($serviceRateFees);
        }

        if ($serviceRate->isParcelService()) {
            $serviceRate->setServiceRateParcelFees($serviceRateParcelFees);
        }
    }

    /**
     * Handle the ServiceRate "updated" event.
     *
     * @return void
     */
    public function updated(ServiceRate $serviceRate)
    {
        $serviceRateFees       = request()->input('serviceRate.rate_fees');
        $serviceRateParcelFees = request()->input('serviceRate.parcel_fees');

        if ($serviceRate->isFixedMeter() || $serviceRate->isPerDrop()) {
            $serviceRate->setServiceRateFees($serviceRateFees);
        }

        if ($serviceRate->isParcelService()) {
            $serviceRate->setServiceRateParcelFees($serviceRateParcelFees);
        }
    }

    /**
     * Handle the ServiceRate "creating" event.
     *
     * @return void
     */
    public function deleted(ServiceRate $serviceRate)
    {
        $serviceRate->load(['parcelFees', 'rateFees']);

        Utils::deleteModels($serviceRate->parcelFees);
        Utils::deleteModels($serviceRate->rateFees);
    }
}
