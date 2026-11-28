<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns;

use Fleetbase\FleetOps\Models\Driver;

/**
 * Finds a driver for endpoints that hold something personal to that driver.
 *
 * An organization's API key reaches any of its drivers. A driver signed in with
 * their own token reaches only themselves.
 */
trait ResolvesOwnDriver
{
    /** Whether the caller is a person signed in with their own token, not an organization's key. */
    protected static function callerIsPerson(): bool
    {
        return (bool) session('is_sanctum_token');
    }

    /** The driver record of the person signed in, in the organization they are signed in to. */
    protected static function callerDriver(): ?Driver
    {
        if (!static::callerIsPerson() || !session('user')) {
            return null;
        }

        return Driver::where('user_uuid', session('user'))->where('company_uuid', session('company'))->first();
    }

    protected function ownDriver(string $id): ?Driver
    {
        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return null;
        }

        if (session('company') && $driver->company_uuid !== session('company')) {
            return null;
        }
        if (static::callerIsPerson() && $driver->user_uuid !== session('user')) {
            return null;
        }

        return $driver;
    }
}
