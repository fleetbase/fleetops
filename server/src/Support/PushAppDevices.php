<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * Links a device to the push app it registered from. An app sends its push app key as
 * `push_app` with `register-device`, and its APNs build as `environment` (`production` or
 * `sandbox`); the device's pushes then use that app's own credentials. A device registered
 * without a key keeps the install-wide push settings.
 */
class PushAppDevices
{
    /**
     * The push app the request names, null when it names none, or false when the key isn't
     * one of the company's active push apps.
     */
    public static function resolve(Request $request, ?string $companyUuid): PushApp|false|null
    {
        $key = trim((string) $request->input('push_app'));
        if ($key === '') {
            return null;
        }

        return PushApp::findByKey($key, $companyUuid) ?? false;
    }

    public static function link(mixed $device, ?PushApp $app, Request $request): mixed
    {
        if (!$app || !$device instanceof UserDevice) {
            return $device;
        }

        $attributes  = $app->deviceAttributes();
        $environment = strtolower(trim((string) $request->input('environment')));
        if (in_array($environment, ['production', 'sandbox'], true)) {
            $attributes['environment'] = $environment;
        }
        $device->fill($attributes + ['status' => 'active']);
        if ($device->isDirty()) {
            $device->save();
        }

        return $device;
    }
}
