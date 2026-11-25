<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;

class NavigatorController extends Controller
{
    /**
     * Retrieve the driver onboard settings for an organization.
     *
     * The organization is resolved from its public id. An unknown public id is a client
     * error, not a server error, so it answers 404 rather than dereferencing a null company.
     *
     * @return JsonResponse
     */
    public function getDriverOnboardSettings($companyId)
    {
        $company = $this->findCompanyByPublicId($companyId);
        if (!$company) {
            return $this->errorResponse('Organization not found.', 404);
        }

        $driverOnboardSettings = $this->driverOnboardSetting($company->uuid);
        if (!$driverOnboardSettings) {
            $driverOnboardSettings = [];
        }

        // `onboarding` is the same settings in the form the driver app reads: what a
        // new driver may do from the app, and what they will be asked for.
        $settings  = is_array($driverOnboardSettings) ? $driverOnboardSettings : [];
        $enabled   = !empty($settings['enableDriverOnboardFromApp']);
        $method    = $enabled && in_array($settings['driverOnboardAppMethod'] ?? null, ['invite', 'button'], true) ? $settings['driverOnboardAppMethod'] : null;
        $documents = $enabled && !empty($settings['driverMustProvideOnboardDocuments']) ? array_values(array_filter((array) ($settings['requiredOnboardDocuments'] ?? []), 'is_string')) : [];

        return $this->jsonResponse([
            'driverOnboardSettings' => $driverOnboardSettings,
            'onboarding'            => [
                'organization'       => ['id' => $company->public_id, 'name' => $company->name],
                'enabled'            => $enabled,
                'method'             => $method,
                // Anyone may create a driver account from the app.
                'self_sign_up'       => $method === 'button',
                // Drivers are added by the organization and invited.
                'invite_only'        => $method === 'invite',
                'documents_required' => count($documents) > 0,
                'documents'          => $documents,
            ],
        ]);
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

    protected function errorResponse(string $message, int $statusCode): JsonResponse
    {
        return response()->error($message, $statusCode);
    }
}
