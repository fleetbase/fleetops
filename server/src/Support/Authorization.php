<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\Permission;
use Fleetbase\Support\Auth;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Explicit Fleet-Ops permission checks for endpoints that AuthorizationGuard
 * cannot resolve correctly on its own: controllers that are not resource
 * controllers, and custom actions whose method name does not match a schema
 * action (for example `bulkDispatch`, which the guard maps to `create order`).
 *
 * Mirrors the guard's semantics:
 *  - platform admins always pass;
 *  - a permission is granted by its exact name, `fleet-ops * {resource}` or `fleet-ops *`;
 *  - while the resource has no permission rows at all (the schema has not been
 *    seeded yet) the check passes, exactly like an unguarded resource.
 *
 * Methods that call this must carry `#[SkipAuthorizationCheck]` so the guard
 * does not also demand its own (wrong) permission.
 */
class Authorization
{
    public const SERVICE = 'fleet-ops';

    /**
     * Abort with a 401 JSON response unless the user holds at least one of the permissions.
     *
     * @param string ...$permissions Short form `{action} {resource}`, e.g. `dispatch order`
     *
     * @throws HttpResponseException
     */
    public static function authorize(string ...$permissions): void
    {
        if (static::canAny(...$permissions)) {
            return;
        }

        throw new HttpResponseException(response()->json(['errors' => ['User is not authorized to ' . $permissions[0]]], 401));
    }

    /**
     * Abort with a 401 JSON response unless the current user is a platform administrator.
     *
     * @throws HttpResponseException
     */
    public static function authorizeAdmin(): void
    {
        $user = static::user();
        if ($user && $user->isAdmin()) {
            return;
        }

        throw new HttpResponseException(response()->json(['errors' => ['This action requires a system administrator']], 401));
    }

    public static function canAny(string ...$permissions): bool
    {
        $user = static::user();
        if (!$user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (static::can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function can(string $permission): bool
    {
        [$action, $resource] = explode(' ', $permission, 2);

        // Same test as Auth::isResourceGuarded(): no permission rows for the resource yet.
        if (!Permission::where('name', 'like', static::SERVICE . ' % ' . $resource)->exists()) {
            return true;
        }

        try {
            return Auth::can(static::SERVICE . " {$action} {$resource}");
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function user()
    {
        try {
            return Auth::getUserFromSession();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
