<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Driver;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DriverExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($driver): array
    {
        return [
            $driver->public_id,
            $driver->internal_id,
            $driver->name,
            $driver->email,
            $driver->vendor_name,
            $driver->vehicle_name,
            $driver->phone,
            $driver->drivers_license_number,
            $driver->license_expiry,
            $driver->country,
            $driver->city,
            $driver->currency,
            $this->yesNo($driver->online),
            $driver->status,
            $driver->orders()->count(),
            data_get($driver, 'currentOrder.tracking') ?? data_get($driver, 'currentOrder.public_id'),
            $driver->created_at,
            $driver->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Email',
            'Vendor',
            'Vehicle',
            'Phone',
            'License #',
            'License Expiry',
            'Country',
            'City',
            'Currency',
            'Online',
            'Status',
            'Assigned Orders Count',
            'Current Order',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => '+#',
            'I' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'R' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    protected function yesNo($value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if ($this->selections) {
            return Driver::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->with(['vehicle', 'currentOrder'])->get();
        }

        return Driver::where('company_uuid', session('company'))->with(['vehicle', 'currentOrder'])->get();
    }
}
