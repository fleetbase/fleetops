<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Support\SocketCluster\SocketToken;

/**
 * The public tracking channel of one customer on one order: `tracking.{opaque}`.
 *
 * The opaque id is derived by core-api (SocketToken::trackingId): lowercase, unpadded base32 of
 * HMAC-SHA256(tracking key, "{order_uuid}:{customer_type}:{customer_uuid}"), first 26 characters,
 * where the tracking key is derived from SOCKETCLUSTER_AUTH_KEY. Nobody can guess or enumerate it
 * without the auth key, and it reveals nothing about the order.
 *
 * The channel registry denies the `tracking` prefix to every principal; the only way in is a
 * `tracking` token from token(), whose scope names exactly this channel.
 */
final class TrackingChannel
{
    public const PREFIX = 'tracking';

    /**
     * The channel name for a customer's scope.
     *
     * @throws \RuntimeException when socket authentication is not configured
     */
    public static function name(TrackingScope $scope): string
    {
        return self::PREFIX . '.' . static::opaqueId($scope);
    }

    /**
     * The opaque id for a customer's scope.
     *
     * @throws \RuntimeException when socket authentication is not configured
     */
    public static function opaqueId(TrackingScope $scope): string
    {
        return SocketToken::trackingId($scope->order_uuid, $scope->customer_type, $scope->customer_uuid);
    }

    /**
     * Mint the token (kind `tracking`, 1800 s) that may subscribe to this scope's channel and nothing else.
     *
     * The caller (the public tracking API) checks its own grant for the scope first.
     *
     * @return array{token: string, expires_in: int, expires_at: string}
     *
     * @throws \RuntimeException when socket authentication is not configured
     */
    public static function token(TrackingScope $scope): array
    {
        return SocketToken::forTracking($scope);
    }
}
