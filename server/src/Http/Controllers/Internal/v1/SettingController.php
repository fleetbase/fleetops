<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Fleetbase\Models\Company;
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

    public function saveEntityEditingSettings(Request $request)
    {
        $entityEditingSettings  = $request->input('entityEditingSettings', []);
        $isEntityFieldsEditable = $request->boolean('isEntityFieldsEditable');

        // Save entity editing settings
        Setting::configure('fleet-ops.entity-editing-settings', $entityEditingSettings);
        Setting::configure('fleet-ops.entity-fields-editable', $isEntityFieldsEditable);

        return response()->json(['entityEditingSettings' => $entityEditingSettings, 'isEntityFieldsEditable' => $isEntityFieldsEditable]);
    }

    public function getEntityEditingSettings()
    {
        $entityEditingSettings  = Setting::where('key', 'fleet-ops.entity-editing-settings')->value('value');
        $isEntityFieldsEditable = Setting::where('key', 'fleet-ops.entity-fields-editable')->value('value');

        return response()->json(['entityEditingSettings' => $entityEditingSettings, 'isEntityFieldsEditable' => $isEntityFieldsEditable]);
    }

    public function saveOnboardSettings(Request $request)
    {
        $enableDriverOnboardFromApp = $request->boolean('enableDriverOnboardFromApp');
        $driverOnboardAppMethod = $request->input('driverOnboardAppMethod');
        $driverProvideOnboardDocuments = $request->boolean('driverProvideOnboardDocuments');
        $requiredOnboardDocuments = $request->input('requiredOnboardDocuments');

        $company = Company::where('uuid', session('company'))->first();

        $onBoardSettings = [
            'enableDriverOnboardFromApp' => $enableDriverOnboardFromApp,
            'driverOnboardAppMethod' => $driverOnboardAppMethod,
            'driverMustProvideOnboardDocuments' => $driverProvideOnboardDocuments,
            'requiredOnboardDocuments' => $requiredOnboardDocuments
        ];

        if (!$company) {
            return response()->error('Company not found');
        }
    
        $companySettings = [
            $company->uuid => $onBoardSettings
        ];
    
        Setting::configure('fleet-ops.driver-onboard', $companySettings);
    
        return response()->json(['onBoardSettings' => $onBoardSettings]);
    }
}