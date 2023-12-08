<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\User;

class NavigatorController extends Controller
{
    public function linkApp()
    {
        $adminUser = User::where("type", "admin")->first();

        if ($adminUser->company) {
            $apiCredential = ApiCredential::firstOrCreate(
                [
                    "user_uuid" => $adminUser->uuid,
                    "company_uuid" => $adminUser->company_uuid,
                    "name" => "NavigationAppLinker",
                ],
                [
                    "user_uuid" => $adminUser->uuid,
                    "company_uuid" => $adminUser->company->uuid,
                    "name" => "NavigationAppLinker",
                ]
            );
            return redirect()->away('flbnavigator://configure?key=' . $apiCredential->key . '&host=' . url()->secure('/'));
        }
        return response()->error("Company not found for the admin user");
    }

    public function getLinkAppUrl()
    {
        return response()->json([
            "linkUrl" => url("int/v1/fleet-ops/navigator/link-app"),
        ]);
    }
}
