<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
use Fleetbase\FleetOps\Integrations\UPS\UPS;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Polls UPS Tracking API for status updates on active orders every 15 minutes.
 *
 * Mirrors PollParcelPathTrackingJob exactly in structure:
 *  - scope: orders whose status is in-flight + whose meta carries a
 *    shipmentIdentificationNumber (UPS 1Z... tracking number)
 *  - per order: resolve the IntegratedVendor bridge, call
 *    getTrackingStatus, firstOrCreate a TrackingStatus row per new
 *    event, transition the Order on terminal events via
 *    ParcelPath::terminalOrderStatus (shared terminal-status helper)
 *  - per-order failures are reported via report() but do not abort
 *    the batch — the next poll cycle picks them up
 *
 * Event code mapping: UPS activity codes (I/D/X/P/M/O/RS) are mapped to
 * Fleetbase TrackingStatus codes by UPS::upsActivityCodeToFleetbaseCode()
 * inside UPS::normalizeTrackingResponse(), which the bridge's
 * getTrackingStatus() wrapper calls before returning.
 */
class PollUPSTrackingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(): void
    {
        $orders = Order::query()
            ->whereIn('status', ['dispatched', 'in_transit', 'out_for_delivery'])
            ->whereNotNull('meta->integrated_vendor_order->shipmentIdentificationNumber')
            ->get();

        foreach ($orders as $order) {
            try {
                $this->pollOrder($order);
            } catch (Throwable $e) {
                report($e);
                continue;
            }
        }
    }

    protected function pollOrder(Order $order): void
    {
        $vendor = IntegratedVendor::find($order->facilitator_uuid);
        if (!$vendor || $vendor->provider !== 'ups') {
            return;
        }

        $bridge = $vendor->api();
        if (!$bridge instanceof UPS) {
            return;
        }

        $trackingNumber = $order->getMeta('integrated_vendor_order.tracking_number')
            ?? $order->getMeta('integrated_vendor_order.shipmentIdentificationNumber');
        if (!$trackingNumber) {
            return;
        }

        $result = $bridge->getTrackingStatus($trackingNumber);
        $trackingNumberModel = $order->trackingNumber;
        if (!$trackingNumberModel) {
            return;
        }

        foreach ($result['events'] as $event) {
            TrackingStatus::firstOrCreate(
                [
                    'tracking_number_uuid' => $trackingNumberModel->uuid,
                    'code'                 => $event['code'],
                    'created_at'           => $event['timestamp'] ?: now(),
                ],
                [
                    'company_uuid' => $order->company_uuid,
                    'status'       => $event['status'] ?: $event['code'],
                    'details'      => $event['location'] ?? $event['details'] ?? null,
                ]
            );
        }

        // Reuse the shared terminal-status helper from ParcelPath (Phase 1 Task 9).
        // DELIVERED -> 'completed', RETURN_TO_SENDER -> 'returned', anything else -> null.
        $terminal = ParcelPath::terminalOrderStatus($result['status'] ?? '');
        if ($terminal && $order->status !== $terminal) {
            $order->status = $terminal;
            $order->save();
        }
    }
}
