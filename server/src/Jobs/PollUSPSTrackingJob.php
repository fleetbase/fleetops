<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
use Fleetbase\FleetOps\Integrations\USPS\USPS;
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
 * Polls USPS Tracking v3 for status updates on active orders every 15 minutes.
 *
 * Mirrors PollParcelPathTrackingJob + PollUPSTrackingJob exactly in
 * structure. Scope: orders whose meta carries
 * integrated_vendor_order.tracking_number AND whose facilitator
 * provider is 'usps'. Uses USPS::getTrackingStatus() which calls
 * USPS::normalizeTrackingResponse() internally — that normalizer
 * maps USPS v3 eventType codes to Fleetbase TrackingStatus codes
 * (ALERT -> EXCEPTION; all others pass through verbatim).
 *
 * Event code mapping: USPS::uspsEventTypeToFleetbaseCode() is the
 * public pure helper that performs the mapping, and it's already
 * unit-tested in USPSLabelBuilderTest.php.
 *
 * Terminal status transitions: reuses ParcelPath::terminalOrderStatus()
 * (the shared helper from Phase 1 Task 9) which maps DELIVERED ->
 * 'completed' and RETURN_TO_SENDER / RETURNED -> 'returned'.
 */
class PollUSPSTrackingJob implements ShouldQueue
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
            ->where(function ($q) {
                // USPS orders store carrier='USPS' in meta
                $q->where('meta->integrated_vendor_order->carrier', 'USPS');
            })
            ->whereNotNull('meta->integrated_vendor_order->tracking_number')
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
        if (!$vendor || $vendor->provider !== 'usps') {
            return;
        }

        $bridge = $vendor->api();
        if (!$bridge instanceof USPS) {
            return;
        }

        $trackingNumber = $order->getMeta('integrated_vendor_order.tracking_number');
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

        $terminal = ParcelPath::terminalOrderStatus($result['status'] ?? '');
        if ($terminal && $order->status !== $terminal) {
            $order->status = $terminal;
            $order->save();
        }
    }
}
