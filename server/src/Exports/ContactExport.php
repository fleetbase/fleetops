<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Contact;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ContactExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{

    /**
     * @return array
     */
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

    /**
     * @return array
     */
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

    /**
     * @return array
     */
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
