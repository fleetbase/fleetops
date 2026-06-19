<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Http\JsonResponse;

class DeviceEventController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'device-event';

    /**
     * Query callback when querying record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onQueryRecord($query, $request): void
    {
        $query->with(['device.telematic']);

        if ($request->filled('telematic')) {
            $query->whereHas('device', function ($deviceQuery) use ($request) {
                $deviceQuery->where('telematic_uuid', $request->input('telematic'));
            });
        }

        if ($request->filled('processed')) {
            $states = Utils::arrayFrom($request->input('processed'));

            if (!$states) {
                return;
            }

            $query->where(function ($query) use ($states) {
                foreach ($states as $state) {
                    match ($state) {
                        'processed'   => $query->orWhereNotNull('processed_at'),
                        'unprocessed' => $query->orWhereNull('processed_at'),
                        default       => null,
                    };
                }
            });
        }
    }

    public function markProcessed(string $id): JsonResponse
    {
        $deviceEvent = DeviceEvent::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $processed = $deviceEvent->markAsProcessed();

        return response()->json([
            'status'       => 'ok',
            'message'      => $processed ? 'Event marked processed.' : 'Event was already processed.',
            'device_event' => $deviceEvent->fresh(),
        ]);
    }
}
