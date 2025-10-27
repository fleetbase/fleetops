<?php

namespace Fleetbase\FleetOps\Contracts;

use Fleetbase\FleetOps\Models\Telematic;

/**
 * Interface TelematicProviderInterface.
 *
 * Core contract that all telematics providers must implement.
 * Defines the standard methods for authentication, device discovery,
 * webhook handling, and data normalization.
 */
interface TelematicProviderInterface
{
    /**
     * Connect to the provider using the given telematic configuration.
     */
    public function connect(Telematic $telematic): void;

    /**
     * Test the connection to the provider.
     *
     * @param array $credentials Provider credentials
     *
     * @return array ['success' => bool, 'message' => string, 'metadata' => array]
     */
    public function testConnection(array $credentials): array;

    /**
     * Fetch devices from the provider.
     *
     * @param array $options Options including limit, cursor, filters
     *
     * @return array ['devices' => array, 'next_cursor' => string|null, 'has_more' => bool]
     */
    public function fetchDevices(array $options = []): array;

    /**
     * Fetch detailed information for a specific device.
     *
     * @param string $externalId Provider's device identifier
     *
     * @return array Device details
     */
    public function fetchDeviceDetails(string $externalId): array;

    /**
     * Normalize a device payload from the provider into FleetOps format.
     *
     * @param array $payload Raw device data from provider
     *
     * @return array Normalized device data
     */
    public function normalizeDevice(array $payload): array;

    /**
     * Normalize an event payload from the provider into FleetOps format.
     *
     * @param array $payload Raw event data from provider
     *
     * @return array Normalized event data
     */
    public function normalizeEvent(array $payload): array;

    /**
     * Normalize sensor data from the provider into FleetOps format.
     *
     * @param array $payload Raw sensor data from provider
     *
     * @return array Normalized sensor data
     */
    public function normalizeSensor(array $payload): array;

    /**
     * Validate a webhook signature.
     *
     * @param string $payload     Raw webhook payload
     * @param string $signature   Signature from webhook headers
     * @param array  $credentials Provider credentials
     *
     * @return bool True if signature is valid
     */
    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool;

    /**
     * Process a webhook payload from the provider.
     *
     * @param array $payload Webhook payload
     * @param array $headers Webhook headers
     *
     * @return array ['devices' => array, 'events' => array, 'sensors' => array]
     */
    public function processWebhook(array $payload, array $headers = []): array;

    /**
     * Get the provider's credential schema.
     *
     * @return array Array of field definitions
     */
    public function getCredentialSchema(): array;

    /**
     * Check if the provider supports webhooks.
     */
    public function supportsWebhooks(): bool;

    /**
     * Check if the provider supports device discovery.
     */
    public function supportsDiscovery(): bool;

    /**
     * Get rate limit information for the provider.
     *
     * @return array ['requests_per_minute' => int, 'burst_size' => int]
     */
    public function getRateLimits(): array;
}
