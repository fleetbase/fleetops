<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Driver and customer login accounts are now managed by their FleetOps
 * profile (user type `driver` or `customer`) instead of in IAM.
 *
 * Before this change the driver form created plain `user` accounts holding only
 * the Driver role, so these accounts show up in IAM and can sign in to the
 * console. This converts them to managed accounts. An account is converted only
 * when it is clearly a profile login and nothing else: it has a driver or
 * customer profile, every organization role it holds is the profile's role, it
 * has no direct permissions or policies, and it owns no organization. Anything
 * else is left as a team member.
 *
 * The previous type is kept in `meta.previous_type` so down() can revert it.
 */
return new class extends Migration {
    private const PROFILE_ROLES = [
        'driver'   => 'Driver',
        'customer' => 'Fleet-Ops Customer',
    ];

    public function up(): void
    {
        if (!$this->hasRequiredTables()) {
            return;
        }

        $converted = ['driver' => 0, 'customer' => 0];

        DB::table('users')
            ->select(['uuid', 'meta'])
            ->where('type', 'user')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereExists(function ($query) {
                    $query->selectRaw(1)->from('drivers')->whereColumn('drivers.user_uuid', 'users.uuid')->whereNull('drivers.deleted_at');
                })->orWhereExists(function ($query) {
                    $query->selectRaw(1)->from('contacts')->whereColumn('contacts.user_uuid', 'users.uuid')->where('contacts.type', 'customer')->whereNull('contacts.deleted_at');
                });
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw(1)->from('companies')->whereColumn('companies.owner_uuid', 'users.uuid');
            })
            ->orderBy('uuid')
            ->chunk(500, function ($users) use (&$converted) {
                foreach ($users as $user) {
                    $type = $this->managedTypeFor($user->uuid);
                    if (!$type) {
                        continue;
                    }

                    $meta                  = json_decode($user->meta ?? '', true);
                    $meta                  = is_array($meta) ? $meta : [];
                    $meta['previous_type'] = 'user';

                    DB::table('users')->where('uuid', $user->uuid)->update(['type' => $type, 'meta' => json_encode($meta)]);
                    $converted[$type]++;
                }
            });

        Log::info('[fleetops] Converted profile-only user accounts to managed accounts.', $converted);
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->select(['uuid', 'meta'])
            ->whereIn('type', array_keys(self::PROFILE_ROLES))
            ->where('meta', 'like', '%"previous_type"%')
            ->orderBy('uuid')
            ->chunk(500, function ($users) {
                foreach ($users as $user) {
                    $meta = json_decode($user->meta ?? '', true);
                    if (!is_array($meta) || ($meta['previous_type'] ?? null) !== 'user') {
                        continue;
                    }

                    unset($meta['previous_type']);
                    DB::table('users')->where('uuid', $user->uuid)->update(['type' => 'user', 'meta' => json_encode($meta)]);
                }
            });
    }

    /**
     * The managed type for the account, or null when it should stay a team member.
     */
    private function managedTypeFor(string $userUuid): ?string
    {
        $hasDriver   = DB::table('drivers')->where('user_uuid', $userUuid)->whereNull('deleted_at')->exists();
        $hasCustomer = DB::table('contacts')->where('user_uuid', $userUuid)->where('type', 'customer')->whereNull('deleted_at')->exists();

        // An account holding both kinds of profile is a person the organization
        // relates to in two ways; leave it for an administrator to decide.
        if ($hasDriver === $hasCustomer) {
            return null;
        }

        $type             = $hasDriver ? 'driver' : 'customer';
        $companyUserUuids = DB::table('company_users')->where('user_uuid', $userUuid)->whereNull('deleted_at')->pluck('uuid');
        $modelUuids       = $companyUserUuids->push($userUuid)->all();

        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('model_has_roles.model_uuid', $modelUuids)
            ->pluck('roles.name')
            ->unique();

        if ($roles->isEmpty() || $roles->contains(fn ($role) => $role !== self::PROFILE_ROLES[$type])) {
            return null;
        }

        $hasPermissions = DB::table('model_has_permissions')->whereIn('model_uuid', $modelUuids)->exists();
        $hasPolicies    = DB::table('model_has_policies')->whereIn('model_uuid', $modelUuids)->exists();

        return $hasPermissions || $hasPolicies ? null : $type;
    }

    private function hasRequiredTables(): bool
    {
        foreach (['users', 'drivers', 'contacts', 'companies', 'company_users', 'roles', 'model_has_roles', 'model_has_permissions', 'model_has_policies'] as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
};
