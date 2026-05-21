<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Helper for resolving the authenticated customer from a request.
 *
 * The customer token is issued by `CustomerController` as a Sanctum
 * `PersonalAccessToken` whose `name` column carries the owning Contact's UUID.
 * Mirrors {@see \Fleetbase\Storefront\Support\Storefront::getCustomerFromToken}
 * but with no Storefront-specific session.
 */
class CustomerAuth
{
    public const HEADER = 'Customer-Token';

    public const APP_BINDING = 'currentCustomer';

    /**
     * Resolve the Contact represented by the `Customer-Token` header.
     *
     * Preference order when a User has more than one customer-type Contact:
     *   1. Contact whose UUID matches the token's `name` column (Storefront convention).
     *   2. Contact in the same company as the resolved API credential (`session('company')`).
     *   3. The first remaining customer-type Contact for the token's User.
     */
    public static function resolveFromHeader(?Request $request = null): ?Contact
    {
        $request = $request ?? request();
        $token   = $request->header(self::HEADER);
        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return null;
        }

        // Storefront convention: token name = Contact UUID.
        if (is_string($accessToken->name) && Str::isUuid($accessToken->name)) {
            $contact = Contact::where('uuid', $accessToken->name)
                ->where('type', 'customer')
                ->first();
            if ($contact) {
                return $contact;
            }
        }

        // Fallback: pick the customer Contact bound to the token's user, preferring
        // the same company as the API credential when available.
        $userId         = $accessToken->tokenable_id ?? null;
        $userIdentifier = $accessToken->tokenable->uuid ?? $userId;
        if (!$userIdentifier) {
            return null;
        }

        $contactQuery = Contact::where('user_uuid', $userIdentifier)
            ->where('type', 'customer');

        $sessionCompany = session('company');
        if ($sessionCompany) {
            $companyPreferred = (clone $contactQuery)
                ->where('company_uuid', $sessionCompany)
                ->first();
            if ($companyPreferred) {
                return $companyPreferred;
            }
        }

        return $contactQuery->first();
    }

    /**
     * Return the currently-bound customer (set by AuthenticateCustomerToken middleware).
     */
    public static function current(): ?Contact
    {
        return app()->bound(self::APP_BINDING) ? app(self::APP_BINDING) : null;
    }

    /**
     * Bind the resolved customer for the current request lifecycle.
     */
    public static function setCurrent(Contact $contact): void
    {
        app()->instance(self::APP_BINDING, $contact);
        session([
            'customer_id' => $contact->uuid,
            'contact_id'  => $contact->uuid,
        ]);
    }
}
