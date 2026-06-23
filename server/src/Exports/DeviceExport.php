<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Device;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DeviceExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($device): array
    {
        return [
            $device->public_id,
            $device->name,
            $device->device_id,
            $device->connection_status,
            $device->attached_to_name,
            $device->telematic_name,
            $device->sensors_count,
            $device->last_online_at,
            $device->provider,
            $device->type,
            $device->serial_number,
            $device->imei,
            $device->status,
            $device->attachable_uuid ? 'Attached' : 'Unattached',
            $this->yesNo($device->online),
            $device->created_at,
            $device->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Provider ID / IMEI',
            'Connection Status',
            'Attached To',
            'Telematic Provider',
            'Sensors Count',
            'Last Seen',
            'Provider',
            'Type',
            'Serial Number',
            'IMEI',
            'Status',
            'Attachment State',
            'Online',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'P' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = Device::where('company_uuid', session('company'))->with(['telematic', 'attachable'])->withCount('sensors');

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
