<?php

namespace Fleetbase\FleetOps\Imports;

use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactImport implements ToCollection, WithHeadingRow
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

            Contact::createFromImport($row, true);
            $this->imported++;
        }
    }
}
