<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;

class NavigatorController extends Controller
{
    /**
     * Redirects to the Fleetbase Navigator app using a deep link.
     * Automatically detects the platform (iOS or Android) and uses the correct URI scheme.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function linkApp(Request $request)
    {
        $adminUser = User::where('type', 'admin')->first();

        if (!$adminUser || !$adminUser->company) {
            return response()->error('Organization for linking not found.');
        }

        $apiCredential = ApiCredential::firstOrCreate(
            [
                'user_uuid'    => $adminUser->uuid,
                'company_uuid' => $adminUser->company_uuid,
                'name'         => 'NavigationAppLinker',
            ],
            [
                'user_uuid'    => $adminUser->uuid,
                'company_uuid' => $adminUser->company->uuid,
                'name'         => 'NavigationAppLinker',
            ]
        );

        $key           = $apiCredential->key;
        $host          = url()->secure('/');
        $socketHost    = env('SOCKETCLUSTER_HOST', 'socket');
        $socketPort    = env('SOCKETCLUSTER_PORT', 8000);
        $socketSecure  = Utils::castBoolean(env('SOCKETCLUSTER_SECURE', false));
        $appIdentifier = config('fleetops.navigator.app_identifier', 'io.fleetbase.navigator');

        $deepLinkParams = http_build_query([
            'key'                  => $key,
            'host'                 => $host,
            'socketcluster_host'   => $socketHost,
            'socketcluster_port'   => $socketPort,
            'socketcluster_secure' => $socketSecure,
        ]);

        $userAgent = $request->header('User-Agent');
        if (stripos($userAgent, 'android') !== false) {
            // Android: Use intent:// scheme
            $intentUrl = "intent://configure?$deepLinkParams#Intent;scheme=flbnavigator;package=" . $appIdentifier . ';end';

            return Redirect::away($intentUrl);
        }

        // Default to iOS (or fallback): Use flbnavigator://
        $iosUrl = "flbnavigator://configure?$deepLinkParams";

        return Redirect::away($iosUrl);
    }

    /**
     * Returns the URL used to link the Fleetbase Navigator app.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLinkAppUrl()
    {
        return response()->json([
            'linkUrl' => url('int/v1/fleet-ops/navigator/link-app'),
        ]);
    }

    /**
     * Retrieves the current organization based on the bearer token (API key or secret).
     *
     * Determines the correct database connection based on the API key format,
     * retrieves the associated API credential, and returns the organization resource.
     *
     * @return \Illuminate\Http\JsonResponse|\App\Http\Resources\Organization
     */
    public function getCurrentOrganization(Request $request)
    {
        $token       = $request->bearerToken();
        $isSecretKey = Str::startsWith($token, '$');

        // Depending on API key format set the connection to find credential on
        $connection = Str::startsWith($token, 'flb_test_') ? 'sandbox' : 'mysql';

        // Find the API Credential record
        $findApKey = ApiCredential::on($connection)
            ->where(function ($query) use ($isSecretKey, $token) {
                if ($isSecretKey) {
                    $query->where('secret', $token);
                } else {
                    $query->where('key', $token);
                }
            })
            ->with(['company.owner'])
            ->withoutGlobalScopes();

        // Get the api credential model record
        $apiCredential = $findApKey->first();

        // Handle no api credential found
        if (!$apiCredential) {
            return response()->error('No API key found to fetch company details with.');
        }

        // Get the organization owning the API key
        $organization = Company::where('uuid', $apiCredential->company_uuid)->first();

        return new Organization($organization);
    }

    /**
     * Retrieves the driver onboarding settings from the system configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings()
    {
        $onBoardSettings  = Setting::where('key', 'fleet-ops.driver-onboard')->value('value');

        return response()->json($onBoardSettings);
    }
}
