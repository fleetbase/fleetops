<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Support\AppReview;

/**
 * App Review access for an organization's customer apps: how Apple and Google reviewers sign
 * in to any app its customers use through Fleet-Ops' customer API (`/v1/customers`).
 *
 * Customer apps sign customers in with a texted code, which a reviewer cannot receive. The
 * mechanism is core's (Fleetbase\Support\AppReview): Fleet-Ops registers the organization as
 * the subject, with the customer apps' own review number, and the owner turns access on in
 * the Console (Fleet-Ops → Settings → Customer Apps). The organization's code signs the number
 * in to its own customer apps only, as a review customer created for that organization the
 * first time. Core never texts the number.
 *
 * Navigator's review type is also registered on Company, so every call here passes this type
 * to AppReview explicitly; AppReview::typeOf() would find Navigator's.
 */
class CustomerAppReview
{
    /**
     * The customer apps' review number, from the 555-0100 to 555-0199 range reserved for
     * fiction. It differs from Navigator's and Storefront's: users are found by phone, and a
     * customer and a driver are different accounts.
     */
    public const PHONE = '+12025550145';

    public const TYPE = 'fleetops-customer';

    public const CUSTOMER_NAME = 'App Reviewer';

    public const OFF_MESSAGE = 'This number is reserved for app store review sign-in, which is turned off for this organization. Turn it on in the Console under Fleet-Ops → Settings → Customer Apps.';

    /**
     * Register the organization's customer apps with core as an App Review subject.
     */
    public static function register(): void
    {
        AppReview::register(static::TYPE, [
            'model'           => Company::class,
            'phone'           => static::PHONE,
            'name'            => 'Customer apps',
            'permission'      => 'fleet-ops update customer-app-settings',
            'view_permission' => 'fleet-ops view customer-app-settings',
        ]);
    }

    /**
     * Whether core's App Review access is installed and understands a type per call.
     */
    public static function isAvailable(): bool
    {
        return class_exists(AppReview::class) && method_exists(AppReview::class, 'resolveType');
    }

    /**
     * Whether an identity is the customer apps' review number, however it is formatted.
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
        return static::isAvailable() && AppReview::isEnabledFor($company, static::TYPE);
    }

    /**
     * Whether a code signs the review number in to this organization's customer apps.
     */
    public static function acceptsCode(?Company $company, ?string $identity, mixed $code): bool
    {
        return static::isAvailable() && static::isReviewPhone($identity) && AppReview::acceptsCode($company, static::PHONE, $code, static::TYPE);
    }

    /**
     * What the Console shows about the organization's access. Never the code.
     */
    public static function status(Company $company): array
    {
        return AppReview::status($company, static::TYPE);
    }

    /**
     * Change the organization's access.
     *
     * @return array{0: \Fleetbase\Models\ReviewAccess, 1: ?string} the access and the new plain code, if one was generated
     */
    public static function update(Company $company, ?bool $enabled, bool $regenerate = false, ?string $updatedByUuid = null): array
    {
        return AppReview::update($company, $enabled, $regenerate, $updatedByUuid, static::TYPE);
    }

    /**
     * The organization's review customer, created the first time a reviewer signs in: an
     * "App Reviewer" account and customer contact in that organization. Each organization has
     * its own: an account of one organization is never linked to another.
     */
    public static function customer(Company $company): Contact
    {
        $contact = Contact::withoutGlobalScopes()
            ->where('company_uuid', $company->uuid)
            ->where('type', 'customer')
            ->whereNull('deleted_at')
            ->whereHas('user', fn ($query) => $query->where('phone', static::PHONE)->whereNull('deleted_at'))
            ->first();

        if ($contact) {
            return $contact;
        }

        $user = ProfileAccountManager::createManagedAccount($company->uuid, 'customer', static::CUSTOMER_NAME, null, static::PHONE, ['status' => 'active']);
        $user->forceFill(['phone_verified_at' => now()])->save();

        return Contact::create([
            'company_uuid' => $company->uuid,
            'user_uuid'    => $user->uuid,
            'type'         => 'customer',
            'name'         => static::CUSTOMER_NAME,
            'phone'        => static::PHONE,
        ]);
    }

    /**
     * Whether a customer is a review customer, so what they do can be told apart from real
     * customers' (their orders carry `meta.app_review`).
     */
    public static function isReviewCustomer(?Contact $customer): bool
    {
        // The review customer's contact carries the review number, so any other customer is
        // answered without a query; its account confirms it.
        if (!$customer || !$customer->user_uuid || !static::isReviewPhone($customer->phone)) {
            return false;
        }

        $phone = User::where('uuid', $customer->user_uuid)->withoutGlobalScopes()->value('phone');

        return static::isReviewPhone($phone);
    }
}
