<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Driver;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DriverExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($driver): array
    {
        return [
            $driver->public_id,
            $driver->internal_id,
            $driver->name,
            $driver->vendor_name,
            $driver->vehicle_name,
            $driver->phone,
            $driver->drivers_license_number,
            $driver->country,
            Date::dateTimeToExcel($driver->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Vendor',
            'Vehicle',
            'Phone',
            'License #',
            'Country',
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
        return Driver::where('company_uuid', session('company'))->get();
    }
}
