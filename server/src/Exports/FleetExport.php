<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Fleet;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FleetExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
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
            $fleet->name,
            data_get($fleet, 'serviceArea.name'),
            data_get($fleet, 'parentFleet.name'),
            data_get($fleet, 'vendor.name'),
            data_get($fleet, 'zone.name'),
            $fleet->drivers_count,
            $fleet->drivers_online_count,
            $fleet->vehicles_count,
            $fleet->vehicles_online_count,
            $fleet->task,
            $fleet->status,
            $fleet->created_at,
            $fleet->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Service Area',
            'Parent Fleet',
            'Vendor',
            'Zone',
            'Drivers Count',
            'Drivers Online Count',
            'Vehicles Count',
            'Vehicles Online Count',
            'Task',
            'Status',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'L' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'M' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if ($this->selections) {
            return Fleet::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->with(['serviceArea', 'parentFleet', 'vendor', 'zone'])->get();
        }

        return Fleet::where('company_uuid', session('company'))->with(['serviceArea', 'parentFleet', 'vendor', 'zone'])->get();
    }
}
