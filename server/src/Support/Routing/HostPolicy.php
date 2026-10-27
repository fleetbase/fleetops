<?php

namespace Fleetbase\FleetOps\Support\Routing;

/**
 * Decides whether the server may call an organization's routing host.
 *
 * Organizations can point the Valhalla and VROOM extensions at their own
 * servers, and Fleet-Ops calls those hosts from the server. An organization
 * host must be a plain http(s) URL whose host is, and resolves only to, public
 * addresses. Otherwise any user who can edit routing settings could make the
 * server request loopback, private network or cloud metadata addresses.
 *
 * The same policy as the extensions' own `HostPolicy`, which applies it when
 * the settings are saved. System settings are not checked: only system
 * administrators can change them.
 */
class HostPolicy
{
    /**
     * Addresses an organization host may not resolve to.
     */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, broadcast
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped
        '64:ff9b::/96',       // NAT64
        '64:ff9b:1::/48',     // local NAT64
        '100::/64',           // discard
        '2001::/32',          // Teredo
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * Optional override used by the test suite: hostname => list of IP addresses.
     */
    protected static ?\Closure $hostResolver = null;

    /**
     * Why the URL may not be used as an organization host, or null when it may.
     */
    public static function violation(?string $url, bool $allowPrivateHosts = false): ?string
    {
        $parts = parse_url(trim((string) $url));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return 'The routing host must be an http or https URL.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The routing host must not contain credentials.';
        }

        if ($allowPrivateHosts) {
            return null;
        }

        $host      = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : static::resolve($host);
        if (empty($addresses)) {
            return 'The routing host could not be resolved.';
        }

        foreach ($addresses as $address) {
            if (!static::isPublicAddress($address)) {
                return 'The routing host must not point to a private, loopback or reserved address.';
            }
        }

        return null;
    }

    public static function allows(?string $url, bool $allowPrivateHosts = false): bool
    {
        return static::violation($url, $allowPrivateHosts) === null;
    }

    public static function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        foreach (static::BLOCKED_RANGES as $range) {
            if (static::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    public static function resolveHostsUsing(?\Closure $resolver): void
    {
        static::$hostResolver = $resolver;
    }

    /**
     * Every IPv4 and IPv6 address the hostname resolves to.
     *
     * @return string[]
     */
    protected static function resolve(string $host): array
    {
        if (static::$hostResolver) {
            return (array) call_user_func(static::$hostResolver, $host);
        }

        $ipv4 = @gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique(array_filter([...$ipv4, ...$ipv6])));
    }

    protected static function inRange(string $packed, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $subnet          = inet_pton($subnet);
        if (strlen($subnet) !== strlen($packed)) {
            return false;
        }

        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($subnet, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (ord($packed[$bytes]) & ord($mask)) === (ord($subnet[$bytes]) & ord($mask));
    }
}
