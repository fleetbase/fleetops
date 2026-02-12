<?php

namespace Fleetbase\FleetOps\Imports;

use Fleetbase\FleetOps\Models\FuelReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class FuelReportImport implements ToCollection, WithHeadingRow
{
    /**
     * Counter for successfully imported rows.
     *
     * @var int
     */
    public int $imported = 0;

    /**
     * @return Collection
     */
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if ($row instanceof Collection) {
                $row = array_filter($row->toArray());
            }

            FuelReport::createFromImport($row, true);
            $this->imported++;
        }
    }
}
