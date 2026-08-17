<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class TrackingNumber extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'              => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'            => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'       => $this->when(Http::isInternalRequest(), $this->public_id),
            'status_uuid'     => $this->when(Http::isInternalRequest(), $this->status_uuid),
            'owner_uuid'      => $this->when(Http::isInternalRequest(), $this->owner_uuid),
            'owner_type'      => $this->when(Http::isInternalRequest(), $this->owner_type ? Utils::toEmberResourceType($this->owner_type) : null),
            'tracking_number' => $this->tracking_number,
            'subject'         => Utils::get($this->owner, 'public_id'),
            'region'          => $this->region,
            'status'          => $this->last_status,
            'status_code'     => $this->last_status_code,
            'qr_code'         => $this->qr_code,
            // The raw text encoded inside qr_code, exposed ONLY in debug mode.
            //
            // qr_code is a base64 PNG generated from owner_uuid (TrackingNumber::newBarcode),
            // and the endpoints that consume a scanned code match on that uuid. A client
            // scans the image to obtain it; an automated contract run cannot, so debug
            // builds publish the value beside the image rather than the API growing a
            // decode endpoint or handing out uuids generally.
            //
            // `when()` omits the key entirely when false — it is absent, not null — so a
            // production response is byte-identical to before.
            'qr_code_content' => $this->when(static::exposesQrCodeContent(), fn () => $this->owner_uuid),
            'barcode'         => $this->barcode,
            'url'             => Utils::consoleUrl('track-order', ['order' => $this->tracking_number]),
            'type'            => Utils::getTypeFromClassName($this->owner_type),
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }

    /**
     * Whether the QR code's decoded content may be published.
     *
     * Debug mode only, and fails closed: any failure to determine the debug state — no
     * container, an application without the accessor — answers false rather than
     * defaulting to exposure.
     */
    protected static function exposesQrCodeContent(): bool
    {
        try {
            $app = app();

            if (!is_object($app) || !method_exists($app, 'hasDebugModeEnabled')) {
                return false;
            }

            return (bool) $app->hasDebugModeEnabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'              => $this->public_id,
            'tracking_number' => $this->tracking_number,
            'subject'         => Utils::get($this->owner, 'public_id'),
            'region'          => $this->region,
            'qr_code'         => $this->qr_code,
            'barcode'         => $this->barcode,
            'type'            => Utils::getTypeFromClassName($this->owner_type),
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }
}
