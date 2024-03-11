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
    /**
     * Retrieves the current visibility settings.
     *
     * This method fetches the visibility settings for 'fleet-ops' from the database and
     * returns them in a JSON response. The settings are retrieved based on a specific key
     * from the 'Setting' model.
     *
     * @return \Illuminate\Http\Response the visibility settings in JSON format
     */
    public function getVisibilitySettings()
    {
        $visibilitySettings = Setting::where('key', 'fleet-ops.visibility')->value('value');

        return response()->json(['visibilitySettings' => $visibilitySettings]);
    }

    /**
     * Saves the visibility settings provided in the request.
     *
     * This method accepts visibility settings via the request and updates them in the database.
     * The new settings are saved using a specific key in the 'Setting' model. The method
     * returns the updated settings in a JSON response.
     *
     * @param Request $request the HTTP request containing the visibility settings
     *
     * @return \Illuminate\Http\Response the updated visibility settings in JSON format
     */
    public function saveVisibilitySettings(Request $request)
    {
        $visibilitySettings = $request->input('visibilitySettings', []);

        // save settings
        Setting::configure('fleet-ops.visibility', $visibilitySettings);

        return response()->json(['visibilitySettings' => $visibilitySettings]);
    }
}
