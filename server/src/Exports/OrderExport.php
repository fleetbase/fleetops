<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Order;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrderExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithColumnFormatting
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($order): array
    {
        return [
            $order->public_id,
            $order->driver_name,
            $order->pickup_name,
            $order->dropoff_name,
            $order->customer_name,
            $order->scheduled_at,
            data_get($this, 'trackingNumber.tracking_number'),
            $order->status,   
            Date::dateTimeToExcel($order->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Driver',
            'PickUp',
            'DropOff',
            'Customer',
            'ScheduledAt',
            'TrackingNumber',
            'Status',
            'Created',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'G' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if (!empty($this->selections)) {
            return Order::where("company_uuid", session("company"))
                        ->whereIn("uuid", $this->selections)
                        ->get();
        }

        return Order::where("company_uuid", session("company"))->get();
    }
}
