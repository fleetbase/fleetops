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
        $serviceRateFees       = $this->rateFeesInput();
        $serviceRateParcelFees = $this->parcelFeesInput();

        if ($serviceRate->isFixedMeter() || $serviceRate->isPerDrop() || $serviceRate->isMultiZoneDistance()) {
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
        $serviceRateFees       = $this->rateFeesInput();
        $serviceRateParcelFees = $this->parcelFeesInput();

        if ($serviceRate->isFixedMeter() || $serviceRate->isPerDrop() || $serviceRate->isMultiZoneDistance()) {
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

        $this->deleteModels($serviceRate->parcelFees);
        $this->deleteModels($serviceRate->rateFees);
    }

    protected function rateFeesInput(): mixed
    {
        return request()->input('serviceRate.rate_fees', request()->input('service_rate.rate_fees'));
    }

    protected function parcelFeesInput(): mixed
    {
        return request()->input('serviceRate.parcel_fees', request()->input('service_rate.parcel_fees'));
    }

    protected function deleteModels(mixed $models): void
    {
        Utils::deleteModels($models);
    }
}
