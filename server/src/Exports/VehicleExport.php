<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Vehicle;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class VehicleExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($vehicle): array
    {
        return [
            $vehicle->public_id,
            $vehicle->internal_id,
            $vehicle->display_name,
            $vehicle->driver_name,
            $vehicle->model_data,
            Date::dateTimeToExcel($vehicle->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Driver Assigned',
            'Make',
            'Model',
            'Year',
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
        return Vehicle::where('company_uuid', session('company'))->get();
    }
}
