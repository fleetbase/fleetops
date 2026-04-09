<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
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
 * Polls ParcelPath for tracking updates on active orders every 15 minutes.
 *
 * Scope: orders whose status is one of the in-flight buckets
 * (dispatched / in_transit / out_for_delivery) and which carry a
 * parcelpath_shipment_id in meta.integrated_vendor_order. For each
 * order, asks the ParcelPath bridge for the current tracking state,
 * firstOrCreate()s one TrackingStatus row per new event, and
 * transitions the Order status via ParcelPath::terminalOrderStatus
 * when the carrier reports a terminal event.
 *
 * Per-order failures (HTTP errors, vendor misconfiguration, missing
 * tracking number) are reported via report() but do not abort the
 * batch — the next poll cycle will pick them up.
 */
class PollParcelPathTrackingJob implements ShouldQueue
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
            ->whereNotNull('meta->integrated_vendor_order->parcelpath_shipment_id')
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
        if (!$vendor || $vendor->provider !== 'parcelpath') {
            return;
        }

        $bridge = $vendor->api();
        if (!$bridge instanceof ParcelPath) {
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
