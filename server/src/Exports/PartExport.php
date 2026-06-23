<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Part;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PartExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($part): array
    {
        return [
            $part->public_id,
            $part->name,
            $part->sku,
            $part->type,
            $part->status,
            $part->quantity_on_hand,
            $part->unit_cost,
            $part->msrp,
            $part->currency,
            $part->manufacturer,
            $part->model,
            $part->serial_number,
            $part->barcode,
            $part->vendor_name,
            $part->asset_name,
            $part->warranty_name,
            $part->total_value,
            $this->yesNo($part->is_in_stock),
            $this->yesNo($part->is_low_stock),
            $part->created_at,
            $part->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Part Number',
            'Type',
            'Status',
            'Quantity On Hand',
            'Unit Cost',
            'MSRP',
            'Currency',
            'Manufacturer',
            'Model',
            'Serial Number',
            'Barcode',
            'Vendor',
            'Asset',
            'Warranty',
            'Total Value',
            'In Stock',
            'Low Stock',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'T' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'U' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = Part::where('company_uuid', session('company'))->with(['vendor', 'warranty', 'asset']);

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
