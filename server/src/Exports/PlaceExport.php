<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Place;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PlaceExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($place): array
    {
        return [
            $place->public_id,
            $place->name,
            $place->phone,
            $this->upper($place->address),
            $place->street1,
            $place->street2,
            $this->upper($place->city),
            $this->upper($place->province),
            $place->postal_code,
            $place->neighborhood,
            $place->district,
            $place->building,
            $place->security_access_code,
            $this->upper($place->country_name),
            data_get($place, 'owner.name') ?? data_get($place, 'owner.public_id'),
            $place->type,
            $place->created_at,
            $place->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Phone',
            'Address',
            'Street 1',
            'Street 2',
            'City',
            'Province',
            'Postal Code',
            'Neighborhood',
            'District',
            'Building',
            'Security Access Code',
            'Country',
            'Owner',
            'Type',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => '+#',
            'I' => NumberFormat::FORMAT_GENERAL,
            'P' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    protected function upper($value): ?string
    {
        return $value ? strtoupper($value) : null;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if ($this->selections) {
            return Place::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->with(['owner'])->get();
        }

        return Place::where('company_uuid', session('company'))->with(['owner'])->get();
    }
}
