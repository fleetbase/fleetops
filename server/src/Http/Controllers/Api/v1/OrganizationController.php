<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Return List organizations.
     *
     * @return Organization
     */
    public function listOrganizations(Request $request)
    {
        $limit = $request->input('limit', 10);
        $withDriverOnboardEnabled = $request->boolean('with_driver_onboard');
    
        $companies = Company::whereHas('users')->get()->map(function ($company) {
            return [
                'name' => $company->name,
                'uuid' => $company->uuid,
            ];
        });
    
        if ($withDriverOnboardEnabled) {
            $driverOnboardSettings = Setting::where('key', 'fleet-ops.driver-onboard-settings')->value('value');
    
            $companies = $companies->filter(function ($company) use ($driverOnboardSettings) {
                // Check if $company is an array and has 'uuid' key
                return is_array($company) && array_key_exists('uuid', $company) &&
                    $driverOnboardSettings && isset($driverOnboardSettings[$company['uuid']]) &&
                    data_get($driverOnboardSettings[$company['uuid']], 'enableDriverOnboardFromApp') === true;
            });
        }

        info("Test", [$companies]);
        // limit
        $companies = $companies->take($limit);

        return response()->json($companies);
    }
}
