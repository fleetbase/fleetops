<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;

class NavigatorController extends Controller
{
    /**
     * Retrieve the driver onboard settings.
     *
     * This method retrieves the driver onboard settings for the current company session. If no company session
     * is found in the request, an error response is returned. The method retrieves the company ID from the session,
     * then fetches the saved driver onboard settings. If settings for the current company are found, they are returned,
     * otherwise, default settings are provided.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings(Request $request)
    {
        if (!$request->session()->has('company')) {
            return response()->apiError('No current company session to find onboard settings.');
        }

        $companyId             = session('company');
        $driverOnboardSettings = [
            'enableDriverOnboardFromApp'        => false,
            'driverOnboardAppMethod'            => null,
            'driverMustProvideOnboardDoucments' => false,
            'requiredOnboardDocuments'          => [],
        ];
        $savedDriverOnboardSettings  = Setting::where('key', 'fleet-ops.driver-onboard-settings')->value('value');
        if ($savedDriverOnboardSettings && isset($savedDriverOnboardSettings[$companyId])) {
            $driverOnboardSettings = $savedDriverOnboardSettings[$companyId];
        }

        return response()->json(['driverOnboardSettings' => $driverOnboardSettings]);
    }
}
