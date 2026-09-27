<?php

namespace Fleetbase\FleetOps\Traits;

use Fleetbase\FleetOps\Support\Authorization;

/**
 * Registers controller middleware that enforces an explicit permission per method.
 *
 * Used for endpoints AuthorizationGuard cannot resolve on its own: methods on
 * resource controllers whose names do not match a schema action (these also carry
 * `#[SkipAuthorizationCheck]`) and every method of controllers that are not
 * resource controllers.
 *
 * Map values are one permission in short form (`dispatch order`), a list of
 * permissions of which the user needs any one, or `admin` for system administrators.
 */
trait AuthorizesMethods
{
    /**
     * @param array<string, string|string[]> $map method name => permission(s)
     */
    protected function authorizeMethods(array $map): void
    {
        foreach ($map as $method => $permissions) {
            $this->middleware(function ($request, $next) use ($permissions) {
                if ($permissions === 'admin') {
                    Authorization::authorizeAdmin();
                } else {
                    Authorization::authorize(...(array) $permissions);
                }

                return $next($request);
            })->only($method);
        }
    }
}
