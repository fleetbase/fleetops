<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ServiceRateExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($serviceRate): array
    {
        return [
            $serviceRate->public_id,
            $serviceRate->service_name,
            Str::title($serviceRate->service_type),
            Utils::moneyFormat($serviceRate->base_fee, $serviceRate->currency),
            $serviceRate->rate_calculation_method,
            $serviceRate->service_area_name,
            $serviceRate->zone_name,
            $serviceRate->per_meter_flat_rate_fee,
            $serviceRate->per_meter_unit,
            $serviceRate->max_distance,
            $serviceRate->max_distance_unit,
            $this->yesNo($serviceRate->has_cod_fee),
            $serviceRate->cod_calculation_method,
            $serviceRate->cod_flat_fee,
            $serviceRate->cod_percent,
            $this->yesNo($serviceRate->has_peak_hours_fee),
            $serviceRate->peak_hours_calculation_method,
            $serviceRate->peak_hours_flat_fee,
            $serviceRate->peak_hours_percent,
            $serviceRate->peak_hours_start,
            $serviceRate->peak_hours_end,
            $serviceRate->currency,
            $serviceRate->duration_terms,
            $serviceRate->estimated_days,
            $serviceRate->created_at,
            $serviceRate->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Service',
            'Type',
            'Base Fee',
            'Calculation Method',
            'Service Area',
            'Zone',
            'Per Meter Flat Rate Fee',
            'Per Meter Unit',
            'Max Distance',
            'Max Distance Unit',
            'Has COD Fee',
            'COD Calculation Method',
            'COD Flat Fee',
            'COD Percent',
            'Has Peak Hours Fee',
            'Peak Hours Calculation Method',
            'Peak Hours Flat Fee',
            'Peak Hours Percent',
            'Peak Hours Start',
            'Peak Hours End',
            'Currency',
            'Duration Terms',
            'Estimated Days',
            'Date Created',
            'Date Updated',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'Y' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Z' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    protected function yesNo($value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if ($this->selections) {
            return ServiceRate::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->get();
        }

        return ServiceRate::where('company_uuid', session('company'))->get();
    }
}
