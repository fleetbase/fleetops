<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Fleet;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FleetExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($fleet): array
    {
        return [
            $fleet->public_id,
            $fleet->internal_id,
            $fleet->name,
            $fleet->zone_uuid,
            Date::dateTimeToExcel($fleet->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Zone Assigned',
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
        return Fleet::where('company_uuid', session('company'))->get();
    }
}
