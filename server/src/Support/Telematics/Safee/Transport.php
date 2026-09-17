<?php

namespace Fleetbase\FleetOps\Support\Telematics\Safee;

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/** Safee's documented OAuth and per-user request budget, shared across workers. */
class Transport
{
    protected ?string $token    = null;
    protected ?float $expiresAt = null;

    public function __construct(protected string $baseUrl, protected array $credentials)
    {
        $this->token = $credentials['access_token'] ?? null;
    }

    public function fingerprint(): string
    {
        $credentials = $this->credentials;
        ksort($credentials);

        return hash('sha256', $this->baseUrl . '|' . json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    public function accountKey(): string
    {
        return hash('sha256', implode('|', [$this->baseUrl, $this->credentials['realm_id'] ?? '', $this->credentials['username'] ?? $this->credentials['access_token'] ?? '']));
    }

    public function request(string $method, string $endpoint, array|\stdClass $payload, float $deadline, int $timeout, int $connectTimeout): array
    {
        $refreshed = false;
        while (true) {
            if (!$this->token || ($this->expiresAt !== null && $this->expiresAt <= $this->wallTime() + 5)) {
                $this->token = $this->cachedToken($deadline, $timeout, $connectTimeout);
            }
            $this->reserveRequest($deadline);
            try {
                $request = Http::withHeaders([
                    'Accept'          => 'application/json', 'Content-Type' => 'application/json',
                    'Accept-Language' => $this->credentials['language'] ?? 'en',
                    'Authorization'   => trim(($this->credentials['authorization_scheme'] ?? 'Bearer') . ' ' . $this->token),
                ])->timeout($this->remaining($deadline, $timeout))
                    ->connectTimeout($this->remaining($deadline, $connectTimeout));
                $response = $method === 'GET' ? $request->get($this->baseUrl . $endpoint) : $request->post($this->baseUrl . $endpoint, $payload);
            } catch (ConnectionException $e) {
                throw new TelematicProviderException('Safee request timed out or could not connect.', ['endpoint' => $endpoint], previous: $e);
            }
            $this->checkThrottle($response);
            // A rejected token is refreshed once; a second rejection fails as an unsuccessful response.
            if ($response->status() === 401 && !$refreshed && $this->canAuthenticate()) {
                $refreshed   = true;
                $this->token = $this->cachedToken($deadline, $timeout, $connectTimeout, $this->token);
                continue;
            }
            if ($response->failed()) {
                throw new TelematicProviderException('Safee API request failed with status ' . $response->status(), ['endpoint' => $endpoint, 'status' => $response->status()]);
            }
            $json = $response->json();
            if (!is_array($json) || !array_key_exists('code', $json) || !is_numeric($json['code']) || (float) $json['code'] !== 0.0) {
                throw new TelematicProviderException('Safee returned an invalid or unsuccessful response.', ['endpoint' => $endpoint]);
            }

            return $json;
        }
    }

    public function authenticate(float $deadline, int $timeout = 30, int $connectTimeout = 5): string
    {
        return $this->cachedToken($deadline, $timeout, $connectTimeout);
    }

    protected function canAuthenticate(): bool
    {
        foreach (['realm_id', 'client_id', 'client_secret', 'username', 'password'] as $field) {
            if (empty($this->credentials[$field])) {
                return false;
            }
        }

        return true;
    }

    protected function cachedToken(float $deadline, int $timeout, int $connectTimeout, ?string $rejected = null): string
    {
        if (!$this->canAuthenticate()) {
            throw new \InvalidArgumentException('Safee realm, client and user credentials are required to authenticate.');
        }
        $key = 'safee:token:' . $this->fingerprint();

        return Cache::lock($key . ':lock', 60)->block($this->remaining($deadline, 2), function () use ($key, $deadline, $timeout, $connectTimeout, $rejected) {
            $cached = null;
            if ($encrypted = Cache::get($key)) {
                try {
                    $cached = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException) {
                    // Only the disposable token cache is recoverable this way.
                    Cache::forget($key);
                }
            }
            if (is_array($cached) && is_string($cached['access_token'] ?? null) && $cached['access_token'] !== $rejected && ($cached['expires_at'] ?? 0) > $this->wallTime() + 5) {
                $this->expiresAt = (float) $cached['expires_at'];

                return $cached['access_token'];
            }
            $refresh         = is_array($cached) && is_string($cached['refresh_token'] ?? null) ? $cached['refresh_token'] : null;
            $tokens          = $this->tokenRequest($deadline, $timeout, $connectTimeout, $refresh);
            $lifetime        = is_numeric($tokens['expires_in'] ?? null) ? max(0, (int) $tokens['expires_in']) : 0;
            $this->expiresAt = $lifetime > 0 ? $this->wallTime() + $lifetime : null;
            if ($lifetime > 0) {
                $tokens['expires_at'] = $this->expiresAt;
                Cache::put($key, Crypt::encryptString(json_encode($tokens, JSON_THROW_ON_ERROR)), max($lifetime, min(86400, (int) ($tokens['refresh_expires_in'] ?? $lifetime))));
            } else {
                // An undocumented expiry must not create a long-lived cached token.
                Cache::forget($key);
            }

            return $tokens['access_token'];
        });
    }

    protected function tokenRequest(float $deadline, int $timeout, int $connectTimeout, ?string $refresh = null): array
    {
        $body = ['grant_type' => $refresh ? 'refresh_token' : 'password', 'client_id' => $this->credentials['client_id'], 'client_secret' => $this->credentials['client_secret']];
        $body += $refresh ? ['refresh_token' => $refresh] : ['username' => $this->credentials['username'], 'password' => $this->credentials['password']];
        $this->reserveRequest($deadline);
        try {
            $response = Http::asForm()->acceptJson()->timeout($this->remaining($deadline, $timeout))
                ->connectTimeout($this->remaining($deadline, $connectTimeout))
                ->post($this->baseUrl . '/auth/realms/' . rawurlencode($this->credentials['realm_id']) . '/protocol/openid-connect/token', $body);
        } catch (ConnectionException $e) {
            throw new TelematicProviderException('Safee authentication timed out or could not connect.', previous: $e);
        }
        $this->checkThrottle($response);
        // Expired/revoked refresh tokens may be exchanged for a new password grant once.
        if ($refresh && in_array($response->status(), [400, 401], true)) {
            return $this->tokenRequest($deadline, $timeout, $connectTimeout);
        }
        if ($response->failed()) {
            throw new TelematicProviderException('Safee authentication failed with status ' . $response->status());
        }
        $tokens = $response->json();
        if (!is_array($tokens) || !is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') {
            throw new TelematicProviderException('Safee authentication did not return an access token.');
        }

        return array_intersect_key($tokens, array_flip(['access_token', 'refresh_token', 'expires_in', 'refresh_expires_in']));
    }

    protected function reserveRequest(float $deadline): void
    {
        $key = 'safee:rate:' . $this->accountKey();
        Cache::lock($key . ':lock', 5)->block($this->remaining($deadline, 2), function () use ($key, $deadline) {
            $this->remaining($deadline, 1);
            $now      = $this->wallTime();
            $until    = (float) Cache::get($key . ':blocked', 0);
            $requests = array_values(array_filter(Cache::get($key, []), fn ($at) => $at > $now - 1));
            if ($until > $now || count($requests) >= 50) {
                throw new TelematicRateLimitExceededException('Safee request budget exhausted.', ['retry_after' => max(1, (int) ceil(max($until, ($requests[0] ?? $now) + 1) - $now))]);
            }
            $requests[] = $now;
            Cache::put($key, $requests, 2);
        });
    }

    protected function checkThrottle(Response $response): void
    {
        if ($response->status() !== 429) {
            return;
        }
        $header = $response->header('Retry-After');
        $delay  = is_numeric($header) ? (int) ceil((float) $header) : max(1, (strtotime($header ?: '') ?: time() + 1) - time());
        $delay  = max(1, min($delay, 3600));
        $key    = 'safee:rate:' . $this->accountKey();
        Cache::lock($key . ':lock', 5)->block(1, function () use ($key, $delay) {
            $until = max((float) Cache::get($key . ':blocked', 0), $this->wallTime() + $delay);
            Cache::put($key . ':blocked', $until, (int) ceil($until - $this->wallTime()));
        });

        throw new TelematicRateLimitExceededException('Safee rate limited the request.', ['retry_after' => $delay]);
    }

    protected function remaining(float $deadline, int $maximum): int
    {
        $remaining = (int) floor($deadline - $this->time());
        if ($remaining < 1) {
            throw new TelematicProviderException('Safee request time budget exhausted before another operation could start.');
        }

        return min(max(1, $maximum), $remaining);
    }

    protected function time(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    protected function wallTime(): float
    {
        return microtime(true);
    }
}
