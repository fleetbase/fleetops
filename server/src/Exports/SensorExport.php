<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Sensor;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SensorExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($sensor): array
    {
        return [
            $sensor->public_id,
            $sensor->name,
            data_get($sensor, 'telematic.name') ?? $sensor->telematic_uuid,
            $sensor->device_name,
            $sensor->type,
            $sensor->last_value,
            $sensor->unit,
            $sensor->status,
            $sensor->threshold_status,
            $sensor->min_threshold,
            $sensor->max_threshold,
            $sensor->serial_number,
            $sensor->imei,
            $sensor->last_reading_at,
            $sensor->attached_to_name,
            $this->yesNo($sensor->is_active),
            $sensor->created_at,
            $sensor->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Telematic',
            'Device',
            'Type',
            'Last Value',
            'Unit',
            'Status',
            'Threshold Status',
            'Min Threshold',
            'Max Threshold',
            'Serial Number',
            'IMEI',
            'Last Reading',
            'Attached To',
            'Active',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'N' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'R' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = Sensor::where('company_uuid', session('company'))->with(['telematic', 'device', 'sensorable']);

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
