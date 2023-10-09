<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Place;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PlaceExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($place): array
    {
        return [
            $place->public_id,
            $place->internal_id,
            $place->display_name,
            $place->address,
            $place->country_name,
            Date::dateTimeToExcel($place->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Address',
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
        return Place::where('company_uuid', session('company'))->get();
    }
}
