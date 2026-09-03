<?php

namespace Fleetbase\FleetOps\Exports;

use Fleetbase\FleetOps\Models\Trailer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TrailerExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(protected array $selections = [])
    {
    }

    public function headings(): array
    {
        return ['ID', 'Name', 'Code', 'Type', 'Status', 'VIN', 'Plate Number', 'Serial Number', 'Make', 'Model', 'Year', 'Length', 'Width', 'Height', 'Tare Weight', 'GVWR', 'Payload Capacity', 'Cargo Volume', 'Axle Count', 'Tire Count', 'Coupling Type', 'Brake Type', 'Refrigerated', 'Measurement System', 'Odometer', 'Odometer Unit', 'Ownership Type', 'Purchased At', 'Lease Expires At', 'Notes', 'Date Created', 'Date Updated'];
    }

    public function map($trailer): array
    {
        return [$trailer->public_id, $trailer->name, $trailer->code, $trailer->type, $trailer->status, $trailer->vin, $trailer->plate_number, $trailer->serial_number, $trailer->make, $trailer->model, $trailer->year, $trailer->length, $trailer->width, $trailer->height, $trailer->tare_weight, $trailer->gvwr, $trailer->payload_capacity, $trailer->cargo_volume, $trailer->axle_count, $trailer->tire_count, $trailer->coupling_type, $trailer->brake_type, $trailer->refrigerated ? 'Yes' : 'No', $trailer->measurement_system, $trailer->odometer, $trailer->odometer_unit, $trailer->ownership_type, $trailer->purchased_at, $trailer->lease_expires_at, $trailer->notes, $trailer->created_at, $trailer->updated_at];
    }

    public function collection()
    {
        return Trailer::where('company_uuid', session('company'))->when($this->selections, fn ($q) => $q->whereIn('uuid', $this->selections))->get();
    }
}
