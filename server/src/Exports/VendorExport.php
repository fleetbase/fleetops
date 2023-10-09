<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Vendor;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class VendorExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{

    /**
     * @return array
     */
    public function map($vendor): array
    {
        return [
            $vendor->public_id,
            $vendor->internal_id,
            $vendor->name,
            $vendor->place_uuid,
            $vendor->email,
            $vendor->phone,
            Date::dateTimeToExcel($vendor->created_at),
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
            'Address',
            'Email',
            'Phone',
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
        return Vendor::where('company_uuid', session('company'))->get();
    }
}

