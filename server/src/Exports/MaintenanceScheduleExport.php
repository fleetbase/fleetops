<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class MaintenanceScheduleExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($schedule): array
    {
        return [
            $schedule->public_id,
            $schedule->name,
            $schedule->subject_name,
            $schedule->type,
            $schedule->status,
            $schedule->interval_method,
            $schedule->interval_type,
            $schedule->interval_value,
            $schedule->interval_unit,
            $schedule->interval_distance,
            $schedule->interval_engine_hours,
            $schedule->last_service_odometer,
            $schedule->last_service_engine_hours,
            $schedule->last_service_date,
            $schedule->next_due_date,
            $schedule->next_due_odometer,
            $schedule->next_due_engine_hours,
            $schedule->default_priority,
            $schedule->default_assignee_name,
            $schedule->last_triggered_at,
            $schedule->created_at,
            $schedule->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Subject',
            'Type',
            'Status',
            'Interval Method',
            'Interval Type',
            'Interval Value',
            'Interval Unit',
            'Interval Distance',
            'Interval Engine Hours',
            'Last Service Odometer',
            'Last Service Engine Hours',
            'Last Service Date',
            'Next Due Date',
            'Next Due Odometer',
            'Next Due Engine Hours',
            'Default Priority',
            'Default Assignee',
            'Last Triggered At',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'N' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'O' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'T' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'U' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'V' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = MaintenanceSchedule::where('company_uuid', session('company'))->with(['subject', 'defaultAssignee']);

        if ($this->selections) {
            $query->whereIn('uuid', $this->selections);
        }

        return $query->get();
    }
}
