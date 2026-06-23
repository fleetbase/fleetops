<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\ServiceArea;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ServiceAreaExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($serviceArea): array
    {
        return [
            $serviceArea->public_id,
            $serviceArea->name,
            $serviceArea->type,
            $serviceArea->zones instanceof Collection ? $serviceArea->zones->map(function ($zone) {
                return $zone->name;
            })->join(', ') : null,
            $serviceArea->country,
            $serviceArea->color,
            $serviceArea->stroke_color,
            $this->yesNo($serviceArea->trigger_on_entry),
            $this->yesNo($serviceArea->trigger_on_exit),
            $serviceArea->dwell_threshold_minutes,
            $serviceArea->speed_limit_kmh,
            $serviceArea->status,
            $serviceArea->created_at,
            $serviceArea->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Type',
            'Zones',
            'Country',
            'Color',
            'Stroke Color',
            'Trigger On Entry',
            'Trigger On Exit',
            'Dwell Threshold Minutes',
            'Speed Limit KMH',
            'Status',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'M' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'N' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    protected function yesNo($value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * @return Collection
     */
    public function collection()
    {
        if ($this->selections) {
            return ServiceArea::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->with(['zones'])->get();
        }

        return ServiceArea::where('company_uuid', session('company'))->with(['zones'])->get();
    }
}
