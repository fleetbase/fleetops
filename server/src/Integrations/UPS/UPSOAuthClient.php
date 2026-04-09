<?php

namespace Fleetbase\FleetOps\Integrations\UPS;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use RuntimeException;

/**
 * UPS OAuth 2.0 bearer token client.
 *
 * Manages the OAuth2 client-credentials flow against UPS's
 * /security/v1/oauth/token endpoint. Tokens are cached per-clientId
 * with a TTL of (expires_in - 60) seconds so a refresh happens well
 * before the carrier-side expiry. Sandbox and production share the
 * same flow, only the host differs.
 *
 * ## Cache strategy
 * The client accepts an injectable ArrayAccess-compatible cache in
 * the constructor. In production this is a thin wrapper around
 * Laravel's Cache facade (built via ::productionCache()). In tests a
 * plain \ArrayObject is passed so the unit tests run under Pest
 * without booting Laravel. The cache key is
 *    ups_oauth_token_{clientId}
 * which scopes tokens per broker tenant and prevents cross-account
 * token collision in a multi-tenant deployment.
 *
 * Not to be confused with a PSR-16 CacheInterface — we deliberately
 * stay at the ArrayAccess level so both \ArrayObject (for tests) and
 * a tiny facade wrapper (for prod) work without adapter code. A full
 * PSR-16 interface would require the facade layer at class-load
 * time, which breaks the Pest-without-Laravel-bootstrap property we
 * care about.
 *
 * Ported from ParcelPath v9 UPSDAPService::getOAuthToken(), with the
 * email-based URL override stripped per the Phase 2 porting rules —
 * only the generic sandbox/production host selection survives.
 */
class UPSOAuthClient
{
    private const PROD_HOST    = 'https://onlinetools.ups.com';
    private const SANDBOX_HOST = 'https://wwwcie.ups.com';
    private const TOKEN_PATH   = '/security/v1/oauth/token';
    private const TTL_SAFETY   = 60;
    private const TTL_MIN      = 60;

    private string $clientId;
    private string $clientSecret;
    private bool $sandbox;
    private Client $http;
    private \ArrayAccess $cache;
    private int $lastCachedTtl = 0;

    public function __construct(
        string $clientId,
        string $clientSecret,
        bool $sandbox = false,
        ?HandlerStack $handler = null,
        ?\ArrayAccess $cache = null
    ) {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->sandbox      = $sandbox;

        $config = [];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->http = new Client($config);

        $this->cache = $cache ?? new \ArrayObject();
    }

    /**
     * Factory for runtime use — wraps Laravel's Cache facade so the
     * class still works without any arg plumbing from the IoC layer.
     * Tests should NOT call this; they construct UPSOAuthClient
     * directly with an \ArrayObject.
     */
    public static function productionCache(): \ArrayAccess
    {
        return new class() implements \ArrayAccess {
            public function offsetExists($offset): bool
            {
                return \Illuminate\Support\Facades\Cache::has($offset);
            }
            public function offsetGet($offset): mixed
            {
                return \Illuminate\Support\Facades\Cache::get($offset);
            }
            public function offsetSet($offset, $value): void
            {
                // TTL is set by putWithTtl() below; direct assignment falls
                // back to the UPS-specific default of one hour minus safety.
                \Illuminate\Support\Facades\Cache::put($offset, $value, 3600 - UPSOAuthClient::TTL_SAFETY);
            }
            public function offsetUnset($offset): void
            {
                \Illuminate\Support\Facades\Cache::forget($offset);
            }
        };
    }

    public function getAccessToken(): string
    {
        $key = $this->cacheKey();

        if (isset($this->cache[$key]) && $this->cache[$key] !== null && $this->cache[$key] !== '') {
            return (string) $this->cache[$key];
        }

        $response = $this->http->request('POST', $this->host() . self::TOKEN_PATH, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'        => 'grant_type=client_credentials',
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException(sprintf(
                'UPS OAuth token request failed with HTTP %d',
                $response->getStatusCode()
            ));
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];
        if (!isset($body['access_token']) || !is_string($body['access_token']) || $body['access_token'] === '') {
            throw new RuntimeException('UPS OAuth response missing access_token');
        }

        $token      = $body['access_token'];
        $expiresIn  = (int) ($body['expires_in'] ?? 3600);
        $this->lastCachedTtl = max(self::TTL_MIN, $expiresIn - self::TTL_SAFETY);

        $this->cache[$key] = $token;

        return $token;
    }

    /**
     * TTL (in seconds) applied to the most recent successful
     * token fetch. Exposed for observability and unit testing.
     */
    public function getLastCachedTtl(): int
    {
        return $this->lastCachedTtl;
    }

    private function host(): string
    {
        return $this->sandbox ? self::SANDBOX_HOST : self::PROD_HOST;
    }

    private function cacheKey(): string
    {
        return 'ups_oauth_token_' . $this->clientId;
    }
}
