<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Support\AppReview;
use Illuminate\Database\Eloquent\Builder;

/**
 * App Review access for the Navigator app: how Apple and Google reviewers sign in to an
 * organization's driver app.
 *
 * Navigator signs drivers in with a texted code, which a reviewer cannot receive. The
 * mechanism is core's (Fleetbase\Support\AppReview): FleetOps registers the organization as
 * the subject, with Navigator's own review number, and the owner turns access on in the
 * Console (Settings → Navigator App). The organization's code signs the number in to its own
 * Navigator only, as a review driver created for that organization the first time. Core never
 * texts the number, and the review driver is never offered or assigned orders automatically.
 */
class NavigatorReview
{
    /**
     * Navigator's review number, from the 555-0100 to 555-0199 range reserved for fiction.
     * It differs from Storefront's: users are found by phone, and a driver and a customer
     * are different accounts.
     */
    public const PHONE = '+12025550144';

    public const TYPE = 'navigator';

    public const DRIVER_NAME = 'App Reviewer';

    public const OFF_MESSAGE = 'This number is reserved for app store review sign-in, which is turned off for this organization.';

    /**
     * Register the organization's Navigator app with core as an App Review subject.
     */
    public static function register(): void
    {
        AppReview::register(static::TYPE, [
            'model'           => Company::class,
            'phone'           => static::PHONE,
            'name'            => 'Navigator',
            'permission'      => 'fleet-ops update navigator-settings',
            'view_permission' => 'fleet-ops view navigator-settings',
        ]);
    }

    public static function isAvailable(): bool
    {
        return class_exists(AppReview::class);
    }

    /**
     * Whether an identity is Navigator's review number, however it is formatted.
     */
    public static function isReviewPhone(?string $identity): bool
    {
        if (blank($identity)) {
            return false;
        }

        return preg_replace('/\D+/', '', $identity) === preg_replace('/\D+/', '', static::PHONE);
    }

    public static function isEnabledFor(?Company $company): bool
    {
        return static::isAvailable() && AppReview::isEnabledFor($company);
    }

    /**
     * Whether a code signs the review number in to this organization's Navigator.
     */
    public static function acceptsCode(?Company $company, ?string $identity, mixed $code): bool
    {
        return static::isAvailable() && static::isReviewPhone($identity) && AppReview::acceptsCode($company, static::PHONE, $code);
    }

    /**
     * The organization's review driver, created the first time a reviewer signs in. Each
     * organization has its own: an account of one organization is never linked to another.
     */
    public static function driver(Company $company): Driver
    {
        $driver = Driver::withoutGlobalScopes()
            ->where('company_uuid', $company->uuid)
            ->whereNull('deleted_at')
            ->whereHas('user', fn ($query) => $query->where('phone', static::PHONE)->whereNull('deleted_at'))
            ->first();

        if ($driver) {
            return $driver;
        }

        $user = ProfileAccountManager::createManagedAccount($company->uuid, 'driver', static::DRIVER_NAME, null, static::PHONE, ['status' => 'active']);
        $user->forceFill(['phone_verified_at' => now()])->save();

        return Driver::create([
            'company_uuid' => $company->uuid,
            'user_uuid'    => $user->uuid,
            'status'       => 'available',
            'online'       => 0,
        ]);
    }

    /**
     * Leave the review drivers out of a driver query (an Eloquent builder, or anything that
     * takes a nested where), so a reviewer is never offered or assigned a real order
     * automatically.
     */
    public static function excludeFrom(mixed $query): mixed
    {
        return $query->where(fn (Builder $drivers) => $drivers->whereDoesntHave('user', fn ($user) => $user->where('phone', static::PHONE)));
    }

    public static function isReviewDriver(?Driver $driver): bool
    {
        if (!$driver || !$driver->user_uuid) {
            return false;
        }

        $phone = User::where('uuid', $driver->user_uuid)->withoutGlobalScopes()->value('phone');

        return static::isReviewPhone($phone);
    }
}
