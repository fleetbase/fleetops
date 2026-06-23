<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Issue;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class IssueExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($issue): array
    {
        return [
            $issue->public_id,
            $issue->issue_id,
            $issue->title,
            $issue->report,
            $issue->priority,
            $issue->type,
            $issue->category,
            collect($issue->tags ?? [])->join(', '),
            $issue->reporter_name,
            $issue->reporter_id,
            $issue->assignee_name,
            $issue->assignee_id,
            $issue->driver_name,
            $issue->vehicle_name,
            $issue->vehicle_id,
            data_get($issue, 'order.public_id') ?? data_get($issue, 'order.tracking'),
            $issue->status,
            $issue->resolved_at,
            $issue->created_at,
            $issue->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Issue ID',
            'Title',
            'Report',
            'Priority',
            'Type',
            'Category',
            'Tags',
            'Reporter',
            'Reporter ID',
            'Assignee',
            'Assignee ID',
            'Driver',
            'Vehicle',
            'Vehicle ID',
            'Linked Order',
            'Status',
            'Resolved At',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'R' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'S' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'T' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if (!empty($this->selections)) {
            return Issue::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->with(['order'])->get();
        }

        return Issue::where('company_uuid', session('company'))->with(['order'])->get();
    }
}
