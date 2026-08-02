<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */

    /**
     * Retrieve driver onboard settings.
     *
     * @return JsonResponse
     */
    public function getDriverOnboardSettings($companyId)
    {
        $company                = $this->findCompanyByPublicId($companyId);
        $driverOnboardSettings  = $this->driverOnboardSetting($company->uuid);
        if (!$driverOnboardSettings) {
            $driverOnboardSettings = [];
        }

        return $this->jsonResponse(['driverOnboardSettings' => $driverOnboardSettings]);
    }

    protected function findCompanyByPublicId(string $companyId): ?Company
    {
        return Company::select()->where('public_id', $companyId)->first();
    }

    protected function driverOnboardSetting(string $companyUuid): mixed
    {
        return Setting::where('key', 'fleet-ops.driver-onboard-settings.' . $companyUuid)->value('value');
    }

    protected function jsonResponse(array $payload): JsonResponse
    {
        return response()->json($payload);
    }
}
