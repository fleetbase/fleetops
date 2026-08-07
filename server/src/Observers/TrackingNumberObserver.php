<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Str;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

class TrackingNumberObserver
{
    /**
     * Listen to the TrackingNumber creating event.
     *
     * @return void
     */
    public function creating(TrackingNumber $trackingNumber)
    {
        // generate a barcode annd qr code
        $trackingNumber->tracking_number = $this->generateTrackingNumber($trackingNumber);
        $trackingNumber->qr_code         = $this->generateBarcode($trackingNumber->owner_uuid, 'QRCODE');
        $trackingNumber->barcode         = $this->generateBarcode($trackingNumber->owner_uuid, 'PDF417');
    }

    /**
     * Listen to the TrackingNumber created event.
     *
     * @return void
     */
    public function created(TrackingNumber $trackingNumber)
    {
        $trackingStatus = $this->createTrackingStatus([
            'company_uuid'         => session('company'),
            'tracking_number_uuid' => $trackingNumber->uuid,
            'status'               => Str::title($trackingNumber->type . ' created'),
            'details'              => 'New ' . Str::lower($trackingNumber->type) . ' created.',
            'location'             => new Point(0.0, 0.0, 4326),
            'code'                 => 'CREATED',
        ]);

        $trackingNumber->updateOwnerStatus($trackingStatus);
    }

    protected function generateTrackingNumber(TrackingNumber $trackingNumber): string
    {
        return TrackingNumber::generateNumber($trackingNumber->region);
    }

    protected function generateBarcode(string $ownerUuid, string $type): string
    {
        return DNS2D::getBarcodePNG($ownerUuid, $type);
    }

    protected function createTrackingStatus(array $attributes): TrackingStatus
    {
        return TrackingStatus::create($attributes);
    }
}
