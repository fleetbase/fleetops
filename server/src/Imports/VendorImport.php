<?php

namespace Fleetbase\FleetOps\Imports;

use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class VendorImport implements ToCollection, WithHeadingRow
{
    /**
     * Counter for successfully imported rows.
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

            if (empty($row)) {
                continue;
            }

            $this->createFromImport($row);
            $this->imported++;
        }
    }

    protected function createFromImport(array $row): void
    {
        Vendor::createFromImport($row, true);
    }
}
