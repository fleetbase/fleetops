<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Maintenance;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class MaintenanceExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($maintenance): array
    {
        return [
            $maintenance->public_id,
            $maintenance->summary,
            $maintenance->maintainable_name,
            $maintenance->performed_by_name,
            $maintenance->work_order_subject,
            $maintenance->type,
            $maintenance->status,
            $maintenance->priority,
            $maintenance->odometer,
            $maintenance->engine_hours,
            $maintenance->scheduled_at,
            $maintenance->started_at,
            $maintenance->completed_at,
            $maintenance->labor_cost,
            $maintenance->parts_cost,
            $maintenance->tax,
            $maintenance->total_cost,
            $maintenance->currency,
            $maintenance->duration_hours,
            $this->yesNo($maintenance->is_overdue),
            $maintenance->days_until_due,
            $maintenance->created_at,
            $maintenance->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Summary',
            'Asset',
            'Performed By',
            'Work Order',
            'Type',
            'Status',
            'Priority',
            'Odometer',
            'Engine Hours',
            'Scheduled At',
            'Started At',
            'Completed At',
            'Labor Cost',
            'Parts Cost',
            'Tax',
            'Total Cost',
            'Currency',
            'Duration Hours',
            'Overdue',
            'Days Until Due',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'K' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'L' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'M' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'V' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'W' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = Maintenance::where('company_uuid', session('company'))->with(['maintainable', 'performedBy', 'workOrder']);

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
