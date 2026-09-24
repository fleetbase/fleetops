<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Exceptions\ProfileIdentityConflictException;
use Fleetbase\FleetOps\Mail\CustomerCredentialsMail;
use Fleetbase\FleetOps\Mail\DriverCredentialsMail;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Services\SmsService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Manages the login accounts behind driver, contact and customer profiles.
 *
 * A profile either owns a managed account (user type `driver`, `contact` or
 * `customer`), which it creates, updates and deletes, or it is linked to a
 * staff account (type `user` or `admin`) of the same organization. A staff
 * account is managed in IAM, so a profile never changes its email or phone,
 * resets its password, deactivates it or deletes it.
 */
class ProfileAccountManager
{
    /**
     * User types owned by a profile.
     */
    public const MANAGED_TYPES = ['driver', 'contact', 'customer'];

    /**
     * The organization role given to each managed account type.
     */
    public const ROLES = [
        'driver'   => 'Driver',
        'contact'  => 'Fleet-Ops Contact',
        'customer' => 'Fleet-Ops Customer',
    ];

    /**
     * Whether the account is owned by a driver, contact or customer profile.
     */
    public static function isManagedAccount(?object $user): bool
    {
        return $user !== null && in_array(data_get($user, 'type'), static::MANAGED_TYPES, true);
    }

    /**
     * Whether the account is a team member's account managed in IAM.
     */
    public static function isStaffAccount(?object $user): bool
    {
        return $user !== null && !static::isManagedAccount($user);
    }

    /**
     * Find the active account holding the given email or phone.
     */
    public static function findAccountByIdentity(?string $email, ?string $phone, ?string $ignoreUserUuid = null): ?User
    {
        $email = static::normalizeEmail($email);
        $phone = static::normalizePhone($phone);

        if (!$email && !$phone) {
            return null;
        }

        return User::where(function ($query) use ($email, $phone) {
            if ($email) {
                $query->orWhere('email', $email);
            }

            if ($phone) {
                $query->orWhere('phone', $phone);
            }
        })
            ->when($ignoreUserUuid, fn ($query) => $query->where('uuid', '!=', $ignoreUserUuid))
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Describe what would happen to an email/phone entered on a new or existing
     * profile: `staff` is the team member the profile would link to, `conflict`
     * is why the email/phone cannot be used.
     *
     * @return array{account: ?User, staff: ?User, conflict: ?string}
     */
    public static function lookup(string $companyUuid, string $type, ?string $email, ?string $phone, ?User $currentUser = null): array
    {
        $result  = ['account' => null, 'staff' => null, 'conflict' => null];
        $account = static::findAccountByIdentity($email, $phone, $currentUser?->uuid);

        if (!$account) {
            return $result;
        }

        $result['account'] = $account;
        $field             = static::matchedField($account, $email);

        // An existing profile can't swap its account for another one
        if ($currentUser) {
            $result['conflict'] = static::takenMessage($field);

            return $result;
        }

        try {
            static::assertAccountCanHoldProfile($account, $companyUuid, $type, $field);
        } catch (ProfileIdentityConflictException $e) {
            $result['conflict'] = $e->getMessage();

            return $result;
        }

        if (static::isStaffAccount($account)) {
            $result['staff'] = $account;
        }

        return $result;
    }

    /**
     * Resolve the account for a new profile. A staff member of the organization
     * or a managed account of the same type with the email/phone is linked;
     * otherwise a managed account is created.
     *
     * @param array $attributes extra user attributes: password, status, timezone, avatar_uuid, country, ip_address, meta
     *
     * @throws ProfileIdentityConflictException
     */
    public static function resolveForProfile(string $companyUuid, string $type, ?string $name, ?string $email, ?string $phone, array $attributes = []): User
    {
        $account = static::findAccountByIdentity($email, $phone);

        if ($account) {
            static::assertAccountCanHoldProfile($account, $companyUuid, $type, static::matchedField($account, $email));

            if (static::isManagedAccount($account)) {
                static::attachToCompany($account, $companyUuid, $type);
            }

            return $account;
        }

        return static::createManagedAccount($companyUuid, $type, $name, $email, $phone, $attributes);
    }

    /**
     * Create a managed account and add it to the organization without an invite.
     */
    public static function createManagedAccount(string $companyUuid, string $type, ?string $name, ?string $email, ?string $phone, array $attributes = []): User
    {
        $company  = Company::where('uuid', $companyUuid)->first();
        $password = $attributes['password'] ?? null;
        $userData = array_filter([
            'company_uuid' => $companyUuid,
            'name'         => $name,
            'email'        => static::normalizeEmail($email),
            'phone'        => static::normalizePhone($phone),
            'username'     => User::generateUsername($name ?: Str::random(6)),
            'timezone'     => $attributes['timezone'] ?? $company?->timezone ?? date_default_timezone_get(),
            'status'       => $attributes['status'] ?? 'pending',
            'avatar_uuid'  => $attributes['avatar_uuid'] ?? null,
            'country'      => $attributes['country'] ?? null,
            'ip_address'   => $attributes['ip_address'] ?? null,
            'meta'         => $attributes['meta'] ?? null,
        ], fn ($value) => $value !== null);

        $user           = new User($userData);
        $user->password = $password ?: Str::random(16);
        $user->save();
        $user->setType($type);

        static::attachToCompany($user, $companyUuid, $type);

        return $user;
    }

    /**
     * Push the profile's proxy fields to its account. A staff account only
     * takes the name: its email and phone are its console identity.
     *
     * @throws ProfileIdentityConflictException
     */
    public static function syncProxyFields(?User $user, array $attributes): bool
    {
        if (!$user) {
            return false;
        }

        $fields = static::isStaffAccount($user) ? ['name'] : ['name', 'email', 'phone', 'timezone', 'avatar_uuid', 'password'];
        $input  = array_intersect_key($attributes, array_flip($fields));

        if (array_key_exists('email', $input)) {
            $input['email'] = static::normalizeEmail($input['email']);
        }

        if (array_key_exists('phone', $input)) {
            $input['phone'] = static::normalizePhone($input['phone']);
        }

        $input = array_filter($input, fn ($value, $key) => $value !== null || in_array($key, ['email', 'phone'], true), ARRAY_FILTER_USE_BOTH);
        $input = array_filter($input, fn ($value, $key) => $key === 'password' || $user->{$key} !== $value, ARRAY_FILTER_USE_BOTH);

        if (empty($input)) {
            return false;
        }

        $changedEmail = array_key_exists('email', $input) ? $input['email'] : null;
        $changedPhone = array_key_exists('phone', $input) ? $input['phone'] : null;
        $taken        = static::findAccountByIdentity($changedEmail, $changedPhone, $user->uuid);
        if ($taken) {
            throw new ProfileIdentityConflictException(static::takenMessage(static::matchedField($taken, $changedEmail)), static::matchedField($taken, $changedEmail));
        }

        if (isset($input['password'])) {
            $user->password = $input['password'];
            unset($input['password']);
        }

        $user->fill($input);

        return $user->save();
    }

    /**
     * Release a profile's account after the profile is deleted. A managed
     * account with no other profile is deleted, which frees its email and
     * phone; one still used by another organization's profile only leaves this
     * organization. Staff accounts are never touched.
     */
    public static function releaseForProfile(?User $user, ?string $companyUuid = null): void
    {
        if (!static::isManagedAccount($user) || $user->trashed()) {
            return;
        }

        $remainingProfiles = static::profileCompanies($user);
        if ($remainingProfiles->isEmpty()) {
            $user->delete();

            return;
        }

        if ($companyUuid && !$remainingProfiles->contains($companyUuid)) {
            CompanyUser::where(['company_uuid' => $companyUuid, 'user_uuid' => $user->uuid])->delete();
        }
    }

    /**
     * Generate a new password for the account and send it to the profile.
     *
     * @return string how the credentials were sent: `email` or `sms`
     */
    public static function sendCredentials(Driver|Contact $profile, User $user, ?string $password = null): string
    {
        $password ??= Str::random(12);
        $user->changePassword($password);

        if ($user->status !== 'active') {
            $user->activate();
        }

        return static::deliverCredentials($profile, $user, $password);
    }

    /**
     * Send already set credentials to the profile by email, or by SMS when the
     * account has no email.
     */
    public static function deliverCredentials(Driver|Contact $profile, User $user, string $password): string
    {
        if ($user->email) {
            Mail::to($user)->send($profile instanceof Driver ? new DriverCredentialsMail($password, $profile, $user) : new CustomerCredentialsMail($password, $profile));

            return 'email';
        }

        if ($user->phone) {
            $profile->loadMissing('company');
            $companyName = $profile->company?->name ?? config('app.name');
            $identity    = $user->phone;
            $result      = app(SmsService::class)->send($user->phone, "Your {$companyName} sign-in details. Login: {$identity} Password: {$password}");

            if (is_array($result) && array_key_exists('success', $result) && !$result['success']) {
                throw new \Exception('The credentials could not be texted: ' . ($result['error'] ?? $result['message'] ?? 'the SMS provider refused it.'));
            }

            return 'sms';
        }

        throw new \Exception('This profile has no email or phone to send credentials to.');
    }

    /**
     * Throw when the account can't hold the kind of profile being created.
     *
     * @throws ProfileIdentityConflictException
     */
    public static function assertAccountCanHoldProfile(User $account, string $companyUuid, string $type, string $field = 'email'): void
    {
        if (static::isStaffAccount($account)) {
            if (!static::isCompanyMember($account, $companyUuid)) {
                throw new ProfileIdentityConflictException(static::takenMessage($field), $field);
            }
        } elseif ($account->type !== $type) {
            throw new ProfileIdentityConflictException('This ' . static::fieldLabel($field) . ' is already used by a ' . $account->type . '.', $field);
        }

        if (static::hasProfileInCompany($account, $companyUuid, $type)) {
            throw new ProfileIdentityConflictException('A ' . $type . ' with this ' . static::fieldLabel($field) . ' already exists.', $field);
        }
    }

    public static function hasProfileInCompany(User $user, string $companyUuid, string $type): bool
    {
        if ($type === 'driver') {
            return Driver::withoutGlobalScopes()->where(['user_uuid' => $user->uuid, 'company_uuid' => $companyUuid])->whereNull('deleted_at')->exists();
        }

        return Contact::withoutGlobalScopes()->where(['user_uuid' => $user->uuid, 'company_uuid' => $companyUuid])->whereNull('deleted_at')->exists();
    }

    /**
     * The organizations the account still has a driver or contact profile in.
     */
    public static function profileCompanies(User $user): \Illuminate\Support\Collection
    {
        $drivers  = Driver::withoutGlobalScopes()->where('user_uuid', $user->uuid)->whereNull('deleted_at')->pluck('company_uuid');
        $contacts = Contact::withoutGlobalScopes()->where('user_uuid', $user->uuid)->whereNull('deleted_at')->pluck('company_uuid');

        return $drivers->merge($contacts)->filter()->unique()->values();
    }

    public static function isCompanyMember(User $user, ?string $companyUuid): bool
    {
        if (!$companyUuid) {
            return false;
        }

        return $user->company_uuid === $companyUuid || CompanyUser::where(['company_uuid' => $companyUuid, 'user_uuid' => $user->uuid])->exists();
    }

    /**
     * Add a managed account to the organization with its profile role, without
     * sending an organization invite.
     */
    public static function attachToCompany(User $user, string $companyUuid, string $type): ?CompanyUser
    {
        $company = Company::where('uuid', $companyUuid)->first();
        if (!$company) {
            return null;
        }

        $role        = static::ROLES[$type] ?? static::ROLES['contact'];
        $companyUser = CompanyUser::where(['company_uuid' => $companyUuid, 'user_uuid' => $user->uuid])->first();
        if (!$companyUser) {
            $companyUser = $company->addUser($user, $role);
        } elseif (static::isManagedAccount($user)) {
            $companyUser->assignSingleRole($role);
        }

        if (!$user->company_uuid) {
            $user->forceFill(['company_uuid' => $companyUuid])->saveQuietly();
        }

        $user->setRelation('companyUser', $companyUser);

        return $companyUser;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = is_string($email) ? strtolower(trim($email)) : null;

        return $email ?: null;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $phone = is_string($phone) ? trim($phone) : null;

        return $phone ? Utils::formatPhoneNumber($phone) : null;
    }

    private static function matchedField(User $account, ?string $email): string
    {
        $email = static::normalizeEmail($email);

        return $email && strtolower((string) $account->email) === $email ? 'email' : 'phone';
    }

    private static function fieldLabel(string $field): string
    {
        return $field === 'phone' ? 'phone number' : 'email';
    }

    private static function takenMessage(string $field): string
    {
        return 'This ' . static::fieldLabel($field) . ' is already in use by another account.';
    }
}
