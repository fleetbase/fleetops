<?php

namespace Fleetbase\FleetOps\Imports;

use Fleetbase\FleetOps\Models\Trailer;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class TrailerImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;
    private const TYPES  = ['dry_van', 'reefer', 'flatbed', 'step_deck', 'lowboy', 'tanker', 'bulk', 'dump', 'chassis', 'curtain_side', 'car_carrier', 'livestock', 'logging', 'dolly', 'specialty', 'other'];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $data = $row instanceof Collection ? array_filter($row->toArray(), fn ($v) => $v !== null && $v !== '') : $row;
            if (!$data) {
                continue;
            }
            if (empty($data['name'])) {
                throw ValidationException::withMessages(["row.$index.name" => ['Name is required.']]);
            }
            if (!empty($data['type']) && !in_array($data['type'], self::TYPES, true)) {
                throw ValidationException::withMessages(["row.$index.type" => ['Invalid trailer type.']]);
            }
            $allowed = ['name', 'code', 'description', 'type', 'status', 'vin', 'plate_number', 'serial_number', 'make', 'model', 'year', 'length', 'width', 'height', 'tare_weight', 'gvwr', 'payload_capacity', 'cargo_volume', 'axle_count', 'tire_count', 'coupling_type', 'brake_type', 'refrigerated', 'measurement_system', 'odometer', 'odometer_unit', 'ownership_type', 'purchased_at', 'lease_expires_at', 'notes'];
            Trailer::create(array_merge(array_intersect_key($data, array_flip($allowed)), ['company_uuid' => session('company')]));
            $this->imported++;
        }
    }
}
