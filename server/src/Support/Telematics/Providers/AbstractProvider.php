<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Models\Telematic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class AbstractProvider.
 *
 * Base implementation for all telematics providers.
 * Provides common functionality for HTTP requests, rate limiting,
 * and credential management.
 */
abstract class AbstractProvider implements TelematicProviderInterface
{
    protected Telematic $telematic;
    protected array $credentials     = [];
    protected array $headers         = [];
    protected string $baseUrl        = '';
    protected int $requestsPerMinute = 60;
    protected int $burstSize         = 10;

    /**
     * Connect to the provider.
     */
    public function connect(Telematic $telematic): void
    {
        $this->telematic   = $telematic;
        $this->credentials = json_decode(Crypt::decryptString($telematic->credentials), true);
        $this->prepareAuthentication();
    }

    /**
     * Prepare authentication headers/tokens.
     * Override this in provider implementations.
     */
    abstract protected function prepareAuthentication(): void;

    /**
     * Make an HTTP request to the provider API.
     *
     * @throws TelematicRateLimitExceededException
     */
    protected function request(string $method, string $endpoint, array $data = []): array
    {
        $this->checkRateLimit();

        $url           = $this->baseUrl . $endpoint;
        $correlationId = Str::uuid()->toString();

        Log::info('Provider API request', [
            'correlation_id' => $correlationId,
            'provider'       => class_basename($this),
            'method'         => $method,
            'url'            => $url,
        ]);

        $response = Http::withHeaders($this->headers)
            ->timeout(30)
            ->{strtolower($method)}($url, $data);

        $this->recordRequest();

        if ($response->failed()) {
            Log::error('Provider API request failed', [
                'correlation_id' => $correlationId,
                'status'         => $response->status(),
                'body'           => $response->body(),
            ]);

            throw new \Exception('API request failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Check rate limit using token bucket algorithm.
     *
     * @throws TelematicRateLimitExceededException
     */
    protected function checkRateLimit(): void
    {
        $key    = 'rate_limit:' . class_basename($this) . ':' . $this->telematic->uuid;
        $tokens = Cache::get($key, $this->burstSize);

        if ($tokens <= 0) {
            throw new TelematicRateLimitExceededException('Rate limit exceeded for provider');
        }

        Cache::put($key, $tokens - 1, 60);
    }

    /**
     * Record a request for metrics.
     */
    protected function recordRequest(): void
    {
        $key    = 'rate_limit:' . class_basename($this) . ':' . $this->telematic->uuid;
        $tokens = Cache::get($key, 0);

        // Refill tokens gradually
        if ($tokens < $this->burstSize) {
            Cache::put($key, min($tokens + 1, $this->burstSize), 60);
        }
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function supportsDiscovery(): bool
    {
        return true;
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => $this->requestsPerMinute,
            'burst_size'          => $this->burstSize,
        ];
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        return false;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        return [
            'devices' => [],
            'events'  => [],
            'sensors' => [],
        ];
    }

    public function getCredentialSchema(): array
    {
        return [];
    }
}
