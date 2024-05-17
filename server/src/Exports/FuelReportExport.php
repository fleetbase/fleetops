<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\FuelReport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FuelReportExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($fleet): array
    {
        return [
            $fleet->public_id,
            $fleet->reporter,
            $fleet->driver_name,
            $fleet->vehicle_name,
            $fleet->status,
            $fleet->volume,
            $fleet->odometer,
            Date::dateTimeToExcel($fleet->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Reporter',
            'Driver',
            'Vehicle',
            'Status',
            'Volume',
            'Odometer',
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
        if ($this->selections) {
            return FuelReport::where("company_uuid", session("company"))
                ->whereIn("uuid", $this->selections)
                ->get();
        }

        return FuelReport::where("company_uuid", session("company"))->get();
    }
}
