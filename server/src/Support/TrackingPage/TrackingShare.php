<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Fleetbase\FleetOps\Support\TrackingScope;
use Illuminate\Support\Carbon;

/**
 * A link a customer sends to someone they trust, so they can follow the order live without a
 * code. The token is `{expiry}.{signature}`, signed with the app key over the order, the customer
 * and the expiry, so it only opens that customer's view of that order, and only until it expires.
 */
class TrackingShare
{
    public const HOURS = 12;

    public static function token(string $orderUuid, string $customerUuid, ?Carbon $expiresAt = null): string
    {
        $expires = ($expiresAt ?? Carbon::now()->addHours(self::HOURS))->getTimestamp();

        return $expires . '.' . static::sign($orderUuid, $customerUuid, $expires);
    }

    public static function valid(mixed $token, ?TrackingScope $scope): bool
    {
        if (!$scope || !is_string($token) || !preg_match('/^(\d+)\.([a-f0-9]{64})$/', $token, $parts)) {
            return false;
        }

        $expires = (int) $parts[1];

        return $expires > Carbon::now()->getTimestamp()
            && hash_equals(static::sign((string) $scope->order_uuid, (string) $scope->customer_uuid, $expires), $parts[2]);
    }

    protected static function sign(string $orderUuid, string $customerUuid, int $expires): string
    {
        return hash_hmac('sha256', 'tracking-share|' . $orderUuid . '|' . $customerUuid . '|' . $expires, (string) config('app.key'));
    }
}
