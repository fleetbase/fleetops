<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Str;

class TrackingNumberObserver
{
    /**
     * Listen to the TrackingNumber creating event.
     *
     * @return void
     */
    public function creating(TrackingNumber $trackingNumber)
    {
        // generate the tracking number, then the qr code and barcode printed from it
        $trackingNumber->tracking_number = $this->generateTrackingNumber($trackingNumber);
        $trackingNumber->fill($this->generateCodeImages($trackingNumber->tracking_number, $this->ownerPublicId($trackingNumber)));
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

    /**
     * @return array{qr_code: string, barcode: string}
     */
    protected function generateCodeImages(string $trackingNumber, ?string $ownerPublicId): array
    {
        return TrackingNumber::codeImages($trackingNumber, $ownerPublicId);
    }

    protected function ownerPublicId(TrackingNumber $trackingNumber): ?string
    {
        if (empty($trackingNumber->owner_uuid)) {
            return null;
        }

        $publicId = data_get($trackingNumber, 'owner.public_id');

        return is_string($publicId) && $publicId !== '' ? $publicId : null;
    }

    protected function createTrackingStatus(array $attributes): TrackingStatus
    {
        return TrackingStatus::create($attributes);
    }
}
