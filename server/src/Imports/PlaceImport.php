<?php

namespace Fleetbase\FleetOps\Imports;

use Illuminate\Support\Collection;
use Fleetbase\FleetOps\Models\Place;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PlaceImport implements ToCollection, WithHeadingRow
{
    /**
     * @return Collection
     */
    public function collection(Collection $rows)
    {
        $places = [];

        foreach ($rows as $row) 
        {
            $places[] = Place::createFromImport($row);
        }
        
        return $places;
    }
}
