<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Fleet;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FleetExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($fleet): array
    {
        return [
            $fleet->public_id,
            $fleet->internal_id,
            $fleet->name,
            $fleet->zone_uuid,
            $fleet->created_at,
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
        if ($this->selections) {
            return Fleet::where('company_uuid', session('company'))
                ->whereIn('uuid', $this->selections)
                ->get();
        }

        return Fleet::where('company_uuid', session('company'))->get();
    }
}
