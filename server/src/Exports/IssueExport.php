<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Issue;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class IssueExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithColumnFormatting
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
            $issue->priority,
            $issue->type,
            $issue->category,
            $issue->reporter_name,
            $issue->assignee_name,
            $issue->driver_name,
            $issue->vehicle_name,
            $issue->status,
            Date::dateTimeToExcel($issue->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Priority',
            'Type',
            'Category',
            'Reporter',
            'Assignee',
            'Driver',
            'Vehicle',
            'Status',
            'Created',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'G' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        info("Selections:", [$this->selections]);
        info("Session company:", [session("company")]);

        if (!empty($this->selections)) {
            return Issue::where("company_uuid", session("company"))
                        ->whereIn("uuid", $this->selections)
                        ->get();
        }

        return Issue::where("company_uuid", session("company"))->get();
    }
}
