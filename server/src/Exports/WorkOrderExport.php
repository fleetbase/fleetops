<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\WorkOrder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class WorkOrderExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($workOrder): array
    {
        return [
            $workOrder->public_id,
            $workOrder->code,
            $workOrder->subject,
            $workOrder->category,
            $workOrder->status,
            $workOrder->priority,
            $workOrder->target_name,
            $workOrder->assignee_name,
            $workOrder->opened_at,
            $workOrder->due_at,
            $workOrder->closed_at,
            $workOrder->completion_percentage,
            $this->yesNo($workOrder->is_overdue),
            $workOrder->days_until_due,
            $workOrder->created_at,
            $workOrder->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Code',
            'Subject',
            'Category',
            'Status',
            'Priority',
            'Target',
            'Assignee',
            'Opened At',
            'Due At',
            'Closed At',
            'Completion Percentage',
            'Overdue',
            'Days Until Due',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'I' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'J' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'K' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'O' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'P' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = WorkOrder::where('company_uuid', session('company'))->with(['target', 'assignee']);

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
