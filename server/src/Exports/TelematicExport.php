<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Telematic;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class TelematicExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($telematic): array
    {
        return [
            $telematic->public_id,
            $telematic->name,
            $telematic->provider,
            $telematic->status,
            $telematic->model,
            $telematic->serial_number,
            $telematic->imei,
            $telematic->iccid,
            $telematic->imsi,
            $telematic->msisdn,
            $telematic->last_seen_at,
            $telematic->warranty_name,
            $this->yesNo($telematic->is_online),
            $telematic->signal_strength,
            $telematic->created_at,
            $telematic->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Provider',
            'Status',
            'Model',
            'Serial Number',
            'IMEI',
            'ICCID',
            'IMSI',
            'MSISDN',
            'Last Seen',
            'Warranty',
            'Online',
            'Signal Strength',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'K' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'O' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'P' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function collection()
    {
        $query = Telematic::where('company_uuid', session('company'))->with(['warranty']);

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
