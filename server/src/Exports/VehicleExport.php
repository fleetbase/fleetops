<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Utils;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class VehicleExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    protected array $selections = [];

    protected array $dateHeadings = [
        'Purchased At',
        'Lease Expires At',
        'Loan First Payment',
        'Created At',
        'Updated At',
    ];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($vehicle): array
    {
        $currency = $vehicle->currency ?: 'USD';

        return [
            $vehicle->public_id,
            $vehicle->internal_id,
            $vehicle->display_name,
            $vehicle->description,
            $vehicle->plate_number,
            $vehicle->vin,
            $vehicle->make,
            $vehicle->model,
            $vehicle->year,
            $vehicle->trim,
            $vehicle->color,
            $vehicle->serial_number,
            $vehicle->fuel_card_number,
            $vehicle->class,
            $vehicle->type,
            $vehicle->driver_name,
            $vehicle->vendor_name,
            $vehicle->status,
            $this->yesNo($vehicle->online),
            $vehicle->call_sign,
            $this->locationPart($vehicle->location, 'lat'),
            $this->locationPart($vehicle->location, 'lng'),
            $vehicle->heading,
            $vehicle->altitude,
            $vehicle->speed,
            $vehicle->measurement_system,
            $vehicle->fuel_volume_unit,
            $vehicle->odometer,
            $vehicle->odometer_unit,
            $vehicle->odometer_at_purchase,
            $vehicle->body_type,
            $vehicle->body_sub_type,
            $vehicle->usage_type,
            $vehicle->ownership_type,
            $vehicle->fuel_type,
            $vehicle->transmission,
            $vehicle->engine_number,
            $vehicle->engine_make,
            $vehicle->engine_model,
            $vehicle->engine_family,
            $vehicle->engine_configuration,
            $vehicle->cylinder_arrangement,
            $vehicle->number_of_cylinders,
            $vehicle->engine_size,
            $vehicle->engine_displacement,
            $vehicle->horsepower,
            $vehicle->horsepower_rpm,
            $vehicle->torque,
            $vehicle->torque_rpm,
            $vehicle->fuel_capacity,
            $vehicle->payload_capacity,
            $vehicle->towing_capacity,
            $vehicle->seating_capacity,
            $vehicle->weight,
            $vehicle->length,
            $vehicle->width,
            $vehicle->height,
            $vehicle->cargo_volume,
            $vehicle->payload_capacity_volume,
            $vehicle->payload_capacity_pallets,
            $vehicle->payload_capacity_parcels,
            $vehicle->passenger_volume,
            $vehicle->interior_volume,
            $vehicle->ground_clearance,
            $vehicle->bed_length,
            $vehicle->emission_standard,
            $this->yesNo($vehicle->dpf_equipped),
            $this->yesNo($vehicle->scr_equipped),
            $vehicle->gvwr,
            $vehicle->gcwr,
            $vehicle->currency,
            $this->money($vehicle->acquisition_cost, $currency),
            $this->money($vehicle->current_value, $currency),
            $this->money($vehicle->insurance_value, $currency),
            $vehicle->depreciation_rate,
            $vehicle->estimated_service_life_distance,
            $vehicle->estimated_service_life_distance_unit,
            $vehicle->estimated_service_life_months,
            $vehicle->purchased_at,
            $vehicle->lease_expires_at,
            $vehicle->financing_status,
            $this->money($vehicle->loan_amount, $currency),
            $vehicle->loan_number_of_payments,
            $vehicle->loan_first_payment,
            $this->joinSkills($vehicle->skills),
            $vehicle->time_window_start,
            $vehicle->time_window_end,
            $vehicle->max_tasks,
            $this->yesNo($vehicle->return_to_depot),
            $this->assignedOrdersCount($vehicle),
            $this->currentOrderReference($vehicle),
            $vehicle->notes,
            $vehicle->created_at,
            $vehicle->updated_at,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Internal ID',
            'Name',
            'Description',
            'Plate Number',
            'VIN',
            'Make',
            'Model',
            'Year',
            'Trim',
            'Color',
            'Serial Number',
            'Fuel Card Number',
            'Class',
            'Type',
            'Driver',
            'Vendor',
            'Status',
            'Online',
            'Call Sign',
            'Latitude',
            'Longitude',
            'Heading',
            'Altitude',
            'Speed',
            'Measurement System',
            'Fuel Volume Unit',
            'Odometer',
            'Odometer Unit',
            'Odometer At Purchase',
            'Body Type',
            'Body Sub Type',
            'Usage Type',
            'Ownership Type',
            'Fuel Type',
            'Transmission',
            'Engine Number',
            'Engine Make',
            'Engine Model',
            'Engine Family',
            'Engine Configuration',
            'Cylinder Arrangement',
            'Number Of Cylinders',
            'Engine Size (L)',
            'Engine Displacement (cc)',
            'Horsepower (hp)',
            'Horsepower RPM',
            'Torque (Nm)',
            'Torque RPM',
            'Fuel Capacity (L)',
            'Payload Capacity (kg)',
            'Towing Capacity (kg)',
            'Seating Capacity',
            'Weight (kg)',
            'Length (cm)',
            'Width (cm)',
            'Height (cm)',
            'Cargo Volume (L)',
            'Payload Volume (m3)',
            'Payload Pallets',
            'Payload Parcels',
            'Passenger Volume (L)',
            'Interior Volume (L)',
            'Ground Clearance (cm)',
            'Bed Length (cm)',
            'Emission Standard',
            'DPF Equipped',
            'SCR Equipped',
            'GVWR (kg)',
            'GCWR (kg)',
            'Currency',
            'Acquisition Cost',
            'Current Value',
            'Insurance Value',
            'Depreciation Rate',
            'Estimated Service Life Distance',
            'Estimated Service Life Distance Unit',
            'Estimated Service Life Months',
            'Purchased At',
            'Lease Expires At',
            'Financing Status',
            'Loan Amount',
            'Loan Number Of Payments',
            'Loan First Payment',
            'Vehicle Skills',
            'Time Window Start',
            'Time Window End',
            'Max Tasks',
            'Return To Depot',
            'Assigned Order Count',
            'Current Order Reference',
            'Notes',
            'Created At',
            'Updated At',
        ];
    }

    public function columnFormats(): array
    {
        return collect($this->headings())
            ->mapWithKeys(function ($heading, $index) {
                if (!in_array($heading, $this->dateHeadings, true)) {
                    return [];
                }

                return [$this->columnLetter($index + 1) => NumberFormat::FORMAT_DATE_DDMMYYYY];
            })
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = Vehicle::where('company_uuid', session('company'))->with(['driver.currentOrder', 'vendor']);

        if ($this->selections) {
            $query->whereIn('uuid', $this->selections);
        }

        return $query->get();
    }

    protected function yesNo($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    protected function joinSkills($skills): ?string
    {
        if (empty($skills)) {
            return null;
        }

        if (is_string($skills)) {
            return $skills;
        }

        return collect($skills)->filter()->values()->join(', ');
    }

    protected function locationPart($location, string $part)
    {
        $location = Utils::castPoint($location);

        if (!$location) {
            return null;
        }

        if ($part === 'lat') {
            return method_exists($location, 'getLat') ? $location->getLat() : data_get($location, 'latitude', data_get($location, 'lat'));
        }

        return method_exists($location, 'getLng') ? $location->getLng() : data_get($location, 'longitude', data_get($location, 'lng'));
    }

    protected function money($amount, ?string $currency = 'USD'): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return Utils::moneyFormat($amount, $currency ?: 'USD');
    }

    protected function assignedOrdersCount(Vehicle $vehicle): int
    {
        return Order::where('vehicle_assigned_uuid', $vehicle->uuid)->count();
    }

    protected function currentOrderReference(Vehicle $vehicle): ?string
    {
        $order = data_get($vehicle, 'driver.currentOrder') ?? Order::where('vehicle_assigned_uuid', $vehicle->uuid)
            ->whereNotIn('status', ['completed', 'canceled', 'cancelled'])
            ->latest()
            ->first();

        return data_get($order, 'tracking') ?? data_get($order, 'public_id');
    }

    protected function columnLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }
}
