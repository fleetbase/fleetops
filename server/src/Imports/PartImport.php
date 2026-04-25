<?php

namespace Fleetbase\FleetOps\Imports;

use Fleetbase\FleetOps\Models\Part;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PartImport implements ToCollection, WithHeadingRow
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

            Part::createFromImport($row, true);
            $this->imported++;
        }
    }
}
