<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\ServiceRate;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ServiceRateExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($service_rate): array
    {
        return [
            $service_rate->public_id,
            $service_rate->name,
            $service_rate->vendor_name,
            $service_rate->vehicle_name,
            $service_rate->country,
            Date::dateTimeToExcel($service_rate->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Service',
            'Service Area',
            'Zone',
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
        return ServiceRate::where('company_uuid', session('company'))->get();
    }
}
