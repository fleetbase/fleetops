<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\Company;

class OrganizationController extends Controller
{
    /**
     * Return List organizations.
     *
     * @return Organization
     */
    public function listOrganizations()
    {
        $companies = Company::whereHas('users')->take(10)->get()->map(function ($company) {
            return [
                'name' => $company->name,
                'id'   => $company->public_id,
            ];
        });

        return response()->json($companies);
    }
}
