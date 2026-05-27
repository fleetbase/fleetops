<?php

namespace Fleetbase\FleetOps\Http\Middleware;

use Fleetbase\FleetOps\Support\CustomerAuth;
use Illuminate\Http\Request;

/**
 * Enforce that a Customer-Token header is present and resolves to a
 * customer-type Contact that belongs to the same company as the request's
 * API credential.
 *
 * Apply this middleware to authenticated `/v1/customers/...` routes. The
 * public sign-up / login routes do NOT use it.
 */
class AuthenticateCustomerToken
{
    public function handle(Request $request, \Closure $next)
    {
        $customer = CustomerAuth::resolveFromHeader($request);
        if (!$customer) {
            return response()->apiError('Customer token is missing or invalid.', 401);
        }

        $sessionCompany = session('company');
        if ($sessionCompany && $customer->company_uuid !== $sessionCompany) {
            return response()->apiError('Customer does not belong to this company.', 403);
        }

        CustomerAuth::setCurrent($customer);

        return $next($request);
    }
}
