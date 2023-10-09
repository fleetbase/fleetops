<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Driver;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class DriverExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{

    /**
     * @return array
     */
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

    /**
     * @return array
     */
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

    /**
     * @return array
     */
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
