<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Order;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrderExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($order): array
    {
        $order->loadMissing(['trackingNumber', 'payload', 'customer', 'facilitator', 'driverAssigned', 'vehicleAssigned']);

        return [
            $order->public_id,
            data_get($order, 'trackingNumber.tracking_number'),
            $order->internal_id,
            data_get($order, 'payload.public_id'),
            $order->driver_name,
            $order->vehicle_name,
            $order->customer_name,
            $order->facilitator_name,
            collect(data_get($order, 'payload.entities'))->map(function ($entity) {
                return $entity->sku ?? $entity->public_id;
            })->join('|'),
            $order->total_entities,
            $order->transaction_amount,
            $order->transaction_currency,
            $order->pickup_name,
            $order->dropoff_name,
            $order->return_name,
            collect(data_get($order, 'payload.waypoints'))->map(function ($waypoint) {
                return $waypoint->address;
            })->join('|'),
            $order->scheduled_at,
            $order->type,
            $order->status,
            $order->created_by_name,
            $order->updated_by_name,
            $order->created_at,
            $order->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Tracking Number',
            'Internal ID',
            'Payload ID',
            'Driver',
            'Vehicle',
            'Customer',
            'Facilitator',
            'SKU',
            'Item Count',
            'Transaction Amount',
            'Transaction Currency',
            'Pick Up',
            'Drop Off',
            'Return',
            'Waypoints',
            'Date Scheduled',
            'Type',
            'Status',
            'Created By',
            'Updated By',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'V' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'W' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if (!empty($this->selections)) {
            return Order::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->with(['trackingNumber', 'customer', 'facilitator', 'driverAssigned', 'vehicleAssigned', 'payload'])->get();
        }

        return Order::where('company_uuid', session('company'))->with(['trackingNumber', 'customer', 'facilitator', 'driverAssigned', 'vehicleAssigned', 'payload'])->get();
    }
}
