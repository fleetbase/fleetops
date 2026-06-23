<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Vendor;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class VendorExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($vendor): array
    {
        return [
            $vendor->public_id,
            $vendor->internal_id,
            $vendor->name,
            $vendor->business_id,
            $vendor->address,
            $vendor->email,
            $vendor->website_url,
            $vendor->phone,
            $vendor->type,
            $vendor->country,
            $vendor->status,
            $vendor->created_at,
            $vendor->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Business ID',
            'Address',
            'Email',
            'Website URL',
            'Phone',
            'Type',
            'Country',
            'Status',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => '+#',
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
            return Vendor::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->get();
        }

        return Vendor::where('company_uuid', session('company'))->get();
    }
}
