<?php

namespace Fleetbase\FleetOps\Imports;

use Fleetbase\FleetOps\Models\Equipment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EquipmentImport implements ToCollection, WithHeadingRow
{
    /**
     * Counter for successfully imported rows.
     */
    public int $imported = 0;

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if ($row instanceof Collection) {
                $row = array_filter($row->toArray());
            }

            if (empty($row)) {
                continue;
            }

            Equipment::createFromImport($row, true);
            $this->imported++;
        }
    }
}
