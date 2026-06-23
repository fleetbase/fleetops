<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Equipment;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EquipmentExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($equipment): array
    {
        return [
            $equipment->public_id,
            $equipment->name,
            $equipment->code,
            $equipment->type,
            $equipment->status,
            $equipment->serial_number,
            $equipment->manufacturer,
            $equipment->model,
            $equipment->equipped_to_name,
            $this->yesNo($equipment->is_equipped),
            $equipment->warranty_name,
            $equipment->purchased_at,
            $equipment->purchase_price,
            $equipment->currency,
            $equipment->age_in_days,
            $equipment->depreciated_value,
            $equipment->created_at,
            $equipment->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Code',
            'Type',
            'Status',
            'Serial Number',
            'Manufacturer',
            'Model',
            'Equipped To',
            'Is Equipped',
            'Warranty',
            'Purchased At',
            'Purchase Price',
            'Currency',
            'Age In Days',
            'Depreciated Value',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'L' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'R' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = Equipment::where('company_uuid', session('company'))->with(['warranty', 'equipable']);

        if ($this->selections) {
            $query->whereIn('uuid', $this->selections);
        }

        return $query->get();
    }

    protected function yesNo($value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
