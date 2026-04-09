<?php

namespace Fleetbase\FleetOps\Integrations\ParcelPath;

use Fleetbase\FleetOps\Models\IntegratedVendor;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * ParcelPath API bridge (Mode A — default).
 *
 * Mirrors the Lalamove bridge shape (host / sandboxHost / namespace,
 * Guzzle client, setRequestId / setOptions / setIntegratedVendor
 * chainable setters, private request helpers). The rating, label,
 * tracking, and void methods are added in Tasks 3–5 on this branch.
 */
class ParcelPath
{
    /**
     * API Host URL.
     */
    private string $host = 'https://api.parcelpath.com/';

    /**
     * API Sandbox Host URL.
     */
    private string $sandboxHost = 'https://api-sandbox.parcelpath.com/';

    /**
     * API Namespace.
     */
    private string $namespace = 'v1';

    /**
     * Determines if instance is sandbox instance.
     */
    private bool $isSandbox = false;

    /**
     * ParcelPath API Key.
     */
    private ?string $apiKey;

    /**
     * Applicable request ID.
     */
    private ?string $requestId = null;

    /**
     * Applicable options (carrier_filter, label_format, insurance_default, markup_type, markup_amount).
     */
    private array $options = [];

    /**
     * HTTP Client Instance.
     */
    private Client $client;

    /**
     * The current integrated vendor accessing instance.
     */
    private ?IntegratedVendor $integratedVendor = null;

    public function __construct(?string $apiKey = null, bool $sandbox = false, ?HandlerStack $handler = null)
    {
        $this->isSandbox = $sandbox;
        $this->apiKey    = $apiKey;

        $clientConfig = [
            'base_uri' => $this->buildRequestUrl(),
        ];

        // Injectable handler for tests — prod path is a plain Guzzle client.
        if ($handler !== null) {
            $clientConfig['handler'] = $handler;
        }

        $this->client = new Client($clientConfig);
    }

    public function setRequestId(?string $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function setOptions(?array $options = []): self
    {
        $this->options = array_merge($this->options, (array) $options);

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setIntegratedVendor(IntegratedVendor $integratedVendor): self
    {
        $this->integratedVendor = $integratedVendor;

        return $this;
    }

    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Compose a request URL. Returns the bare base URL when `$path` is empty
     * and `<host><namespace>/<path>` otherwise. Matches Lalamove::buildRequestUrl.
     */
    public function buildRequestUrl(string $path = ''): string
    {
        $host = $this->isSandbox ? $this->sandboxHost : $this->host;

        return trim($host . $this->namespace . '/' . $path);
    }

    /**
     * Execute an authenticated request against the ParcelPath API.
     */
    private function request(string $method, string $path, array $options = [])
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . (string) $this->apiKey,
        ]);

        if ($this->requestId !== null) {
            $options['headers']['X-Request-Id'] = $this->requestId;
        }

        $options['http_errors'] = false;

        $response = $this->client->request($method, $path, $options);
        $body     = (string) $response->getBody();

        return json_decode($body, true);
    }

    public function get(string $path, array $options = [])
    {
        return $this->request('GET', $path, $options);
    }

    public function post(string $path, array $options = [])
    {
        return $this->request('POST', $path, $options);
    }

    public function delete(string $path, array $options = [])
    {
        return $this->request('DELETE', $path, $options);
    }
}
