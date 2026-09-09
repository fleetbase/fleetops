<?php

namespace Fleetbase\FleetOps\Support;

/**
 * Compact towing summary of a trailer for parent payloads (vehicle listings and map
 * popovers) that only need identity, classification and connectivity.
 */
class TrailerSummary
{
    public static function compact($trailer): array
    {
        return [
            'id'                  => $trailer->uuid,
            'uuid'                => $trailer->uuid,
            'public_id'           => $trailer->public_id,
            'name'                => $trailer->name,
            'display_name'        => $trailer->display_name,
            'type'                => $trailer->type,
            'plate_number'        => $trailer->plate_number,
            'status'              => $trailer->status,
            'attachment_state'    => 'attached',
            'connectivity_status' => $trailer->connectivity_status,
            'online'              => (bool) $trailer->online,
        ];
    }

    /**
     * Compact every trailer in a loaded collection.
     */
    public static function collection(iterable $trailers): array
    {
        return collect($trailers)->map(fn ($trailer) => static::compact($trailer))->values()->all();
    }
}
