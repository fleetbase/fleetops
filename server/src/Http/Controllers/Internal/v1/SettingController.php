<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;

/**
 * Class SettingController.
 */
class SettingController extends Controller
{
    public function getVisibilitySettings()
    {
        $visibilitySettings = Setting::where('key', 'fleet-ops.visibility')->value('value');

        return response()->json(['visibilitySettings' => $visibilitySettings]);
    }

    public function saveVisibilitySettings(Request $request)
    {
        $visibilitySettings = $request->input('visibilitySettings', []);

        // save settings
        Setting::configure('fleet-ops.visibility', $visibilitySettings);

        return response()->json(['visibilitySettings' => $visibilitySettings]);
    }
}
