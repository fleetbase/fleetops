<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\InspectionSubmission;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Inspections as a spreadsheet — what a compliance officer is asked for.
 *
 * One row per submission, not per answer: the question a DVIR export answers
 * is "which trucks failed, when, and did anyone deal with it", so the defects
 * are summarised into a column and the follow-up is named. The per-answer
 * detail lives on the record, where the photos are.
 */
class InspectionExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($submission): array
    {
        return [
            $submission->public_id,
            $submission->form_name,
            $submission->vehicle_name,
            $submission->driver_name,
            $submission->type,
            $submission->status,
            $submission->result,
            $submission->source,
            $submission->odometer,
            $submission->engine_hours,
            $submission->total_items,
            $submission->failed_items,
            static::failedLabels($submission),
            static::unsafeLabel($submission),
            $submission->issue?->public_id,
            $submission->workOrder?->public_id,
            $submission->started_at,
            $submission->submitted_at,
            $submission->resolved_at,
            $submission->created_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Form',
            'Vehicle',
            'Driver',
            'Type',
            'Status',
            'Result',
            'Source',
            'Odometer',
            'Engine Hours',
            'Items',
            'Defects',
            'Failed Items',
            'Unsafe',
            'Issue',
            'Work Order',
            'Started',
            'Submitted',
            'Resolved',
            'Date Created',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'R' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'S' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'T' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /** The defects, named, so the spreadsheet says what actually failed. */
    public static function failedLabels(InspectionSubmission $submission): string
    {
        return $submission->itemResults
            ->filter(fn ($result) => !$result->passed)
            ->map(fn ($result) => trim((string) $result->label))
            ->filter()
            ->implode(', ');
    }

    /** Whether the inspection took the vehicle out of service. */
    public static function unsafeLabel(InspectionSubmission $submission): string
    {
        $unsafe = filter_var(data_get($submission->meta, 'unsafe', false), FILTER_VALIDATE_BOOLEAN)
            || $submission->itemResults->contains(fn ($result) => filter_var(data_get($result->meta, 'unsafe', false), FILTER_VALIDATE_BOOLEAN));

        return $unsafe ? 'Yes' : 'No';
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = InspectionSubmission::where('company_uuid', session('company'))
            ->with(['form', 'vehicle', 'driver', 'itemResults', 'issue', 'workOrder']);

        if ($this->selections) {
            $query->whereIn('uuid', $this->selections);
        }

        return $query->get();
    }
}
