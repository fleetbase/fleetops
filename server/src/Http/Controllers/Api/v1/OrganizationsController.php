<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\Company;


class OrganizationsController extends Controller
{
    /**
     * Return List organizations.
     *
     * @return Organization
     */

    public function listOrganizations()
    {
    
        $companies = Company::whereHas('users')->take(10)->get();
        return Organization::collection($companies);
    }
}