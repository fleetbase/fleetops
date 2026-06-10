<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;

class DeviceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'device';

    /**
     * Query callback when querying record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onQueryRecord($query, $request): void
    {
        $query->with(['telematic', 'warranty', 'attachable']);

        if ($request->filled('attachment_state')) {
            match ($request->input('attachment_state')) {
                'attached'   => $query->whereNotNull('attachable_uuid'),
                'unattached' => $query->whereNull('attachable_uuid'),
                default      => null,
            };
        }

        if ($request->filled('vehicle')) {
            $query->where('attachable_uuid', $request->input('vehicle'));
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', 'like', '%' . $request->input('device_id') . '%');
        }
    }

    /**
     * Query callback when finding record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onFindRecord($query, $request): void
    {
        $query->with(['telematic', 'warranty', 'attachable']);
    }
}
