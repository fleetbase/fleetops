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

    /**
     * Save entity editing settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveEntityEditingSettings(Request $request)
    {
        $entityEditingSettings  = $request->input('entityEditingSettings', []);

        // Save entity editing settings
        Setting::configure('fleet-ops.entity-editing-settings', $entityEditingSettings);

        return response()->json(['entityEditingSettings' => $entityEditingSettings]);
    }

    /**
     * Retrieve entity editing settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEntityEditingSettings()
    {
        $entityEditingSettings  = Setting::where('key', 'fleet-ops.entity-editing-settings')->value('value');
        if (!$entityEditingSettings) {
            $entityEditingSettings = [];
        }

        return response()->json(['entityEditingSettings' => $entityEditingSettings]);
    }

    /**
     * Retrieve driver onboard settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings($companyId)
    {
        $driverOnboardSettings  = Setting::where('key', 'fleet-ops.driver-onboard-settings.' . $companyId)->value('value');
        if (!$driverOnboardSettings) {
            $driverOnboardSettings = [];
        }

        return response()->json(['driverOnboardSettings' => $driverOnboardSettings]);
    }

    /**
     * Save driver onboard settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function savedDriverOnboardSettings(Request $request)
    {
        $driverOnboardSettings = $request->input('driverOnboardSettings', []);

        if ($driverOnboardSettings['enableDriverOnboardFromApp'] == false) {
            $driverOnboardSettings['driverMustProvideOnboardDoucments'] = false;
            $driverOnboardSettings['requiredOnboardDocuments']          = [];
            $driverOnboardSettings['driverOnboardAppMethod']            = '';
            $driverOnboardSettings['enableDriverOnboardFromApp']        = false;
        }

        Setting::configure('fleet-ops.driver-onboard-settings.' . $driverOnboardSettings['companyId'], $driverOnboardSettings);

        return response()->json(['driverOnboardSettings' => $driverOnboardSettings]);
    }
}
