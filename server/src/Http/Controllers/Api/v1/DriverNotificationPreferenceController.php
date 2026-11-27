<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesOwnDriver;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\User;
use Fleetbase\Support\NotificationPreferences;
use Illuminate\Http\Request;

/**
 * What a driver wants the Navigator app to ring them about.
 *
 *   GET /v1/drivers/{id}/notification-preferences
 *   PUT /v1/drivers/{id}/notification-preferences   { new_work, order_changes, messages, offers }
 *
 * Each is on until switched off. Switching one off stops the push for that kind
 * of event; an open app still receives it live.
 */
class DriverNotificationPreferenceController extends Controller
{
    use ResolvesOwnDriver;

    public function show(string $id)
    {
        $user = $this->userOf($id);
        if (!$user) {
            return response()->apiError('Driver resource not found.', 404);
        }

        return response()->json(['preferences' => NotificationPreferences::for($user)]);
    }

    public function update(string $id, Request $request)
    {
        $user = $this->userOf($id);
        if (!$user) {
            return response()->apiError('Driver resource not found.', 404);
        }

        $changes = array_intersect_key((array) $request->input('preferences', $request->all()), array_flip(NotificationPreferences::CATEGORIES));
        if (!$changes) {
            return response()->apiError('Send at least one of: ' . implode(', ', NotificationPreferences::CATEGORIES) . '.', 422);
        }

        return response()->json(['preferences' => NotificationPreferences::update($user, $changes)]);
    }

    protected function userOf(string $id): ?User
    {
        $driver = $this->ownDriver($id);

        return $driver && $driver->user_uuid ? User::where('uuid', $driver->user_uuid)->first() : null;
    }
}
