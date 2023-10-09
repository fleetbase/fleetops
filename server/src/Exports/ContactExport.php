<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Contact;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ContactExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($contact): array
    {
        return [
            $contact->public_id,
            $contact->internal_id,
            $contact->name,
            $contact->email,
            $contact->phone,
            Date::dateTimeToExcel($contact->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Address',
            'Email',
            'Phone',
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
        return Contact::where('company_uuid', session('company'))->get();
    }
}
