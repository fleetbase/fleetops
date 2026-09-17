<?php

namespace Fleetbase\FleetOps\Support\FuelProviders\Providers;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SascoFuelProvider extends AbstractFuelProvider
{
    public const BASE_URLS = [
        'sandbox'    => 'https://loyalty-api-qc.sasco.sa',
        'production' => 'https://loyalty-api.sasco.com.sa',
    ];

    public const LOGIN_ENDPOINT        = 'apigateway/api/identity/user/b2badmin-login';
    public const VEHICLES_ENDPOINT     = 'apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles';
    public const TRANSACTIONS_ENDPOINT = 'apigateway/api/analytics/v1/b2bexternal/drivers-transactions';

    /** Fallback token lifetime when the JWT carries no readable exp claim. */
    protected const DEFAULT_TOKEN_TTL = 3600;

    public function key(): string
    {
        return 'sasco';
    }

    public function name(): string
    {
        return 'SASCO';
    }

    public function authenticate(FuelProviderConnection $connection): array
    {
        $credentials = (array) $connection->credentials;
        $response    = Http::baseUrl($this->baseUrl($connection))
            ->timeout((int) data_get($credentials, 'timeout', 30))
            ->acceptJson()
            ->asJson()
            ->post(self::LOGIN_ENDPOINT, [
                'username' => trim((string) data_get($credentials, 'username')),
                'password' => (string) data_get($credentials, 'password'),
            ]);

        $token = $response->json('accessToken');
        if (!$response->successful() || !is_string($token) || $token === '') {
            return [
                'success' => false,
                'message' => $this->errorMessage($response, 'SASCO login failed. Check the B2B admin username and password.'),
                'status'  => $response->status(),
            ];
        }

        Cache::put($this->tokenCacheKey($connection), $token, $this->tokenTtl($token));

        return ['success' => true, 'message' => 'SASCO login successful.', 'token' => $token];
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        $metadata = [
            'environment' => $connection->environment ?? 'production',
            'host'        => parse_url($this->baseUrl($connection), PHP_URL_HOST),
        ];

        try {
            $response           = $this->get($connection, self::VEHICLES_ENDPOINT, ['Page_size' => 1, 'Current_page' => 1]);
            $metadata['status'] = $response->status();

            if (!$response->successful() || !is_array($response->json('vehicles'))) {
                return [
                    'success'  => false,
                    'message'  => $this->errorMessage($response, 'Unable to connect to SASCO.'),
                    'metadata' => $metadata,
                ];
            }

            return [
                'success'  => true,
                'message'  => 'SASCO connection successful.',
                'metadata' => array_merge($metadata, ['total_vehicles' => $response->json('totalItems')]),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'metadata' => $metadata];
        }
    }

    public function listVehicles(FuelProviderConnection $connection, array $options = []): Collection
    {
        return $this->paginatedGet($connection, self::VEHICLES_ENDPOINT, 'vehicles', $options);
    }

    public function listTransactions(FuelProviderConnection $connection, Carbon $from, Carbon $to, array $options = []): Collection
    {
        $params = [
            'FromDate' => $from->toDateString(),
            'ToDate'   => $to->toDateString(),
        ];

        if (array_key_exists('is_trip', $options)) {
            $params['IsTrip'] = $options['is_trip'] ? 'true' : 'false';
        }

        return $this->paginatedGet($connection, self::TRANSACTIONS_ENDPOINT, 'driverTransactions', array_merge($options, $params))
            ->map(fn ($payload) => $this->normalizeTransaction($payload));
    }

    protected function baseUrl(FuelProviderConnection $connection): string
    {
        $credentials = (array) $connection->credentials;
        $override    = trim((string) data_get($credentials, 'base_url', ''));

        return rtrim($override ?: self::BASE_URLS[$connection->environment ?? 'production'], '/');
    }

    protected function headers(FuelProviderConnection $connection): array
    {
        return ['Authorization' => 'Bearer ' . $this->token($connection)];
    }

    /**
     * Returns a cached access token, logging in when none is cached.
     */
    protected function token(FuelProviderConnection $connection): string
    {
        $token = Cache::get($this->tokenCacheKey($connection));
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $result = $this->authenticate($connection);
        if (!$result['success']) {
            throw new \RuntimeException($result['message']);
        }

        return $result['token'];
    }

    /**
     * Keyed by host and username rather than connection uuid so unsaved
     * connections built for credential tests share the same cache entry.
     */
    protected function tokenCacheKey(FuelProviderConnection $connection): string
    {
        $credentials = (array) $connection->credentials;

        return 'fuel-provider:sasco:token:' . hash('sha256', $this->baseUrl($connection) . '|' . trim((string) data_get($credentials, 'username')));
    }

    protected function tokenTtl(string $token): int
    {
        $segments = explode('.', $token);
        $payload  = isset($segments[1]) ? json_decode((string) base64_decode(strtr($segments[1], '-_', '+/')), true) : null;
        $expires  = (int) data_get($payload, 'exp', 0);

        if ($expires <= 0) {
            return self::DEFAULT_TOKEN_TTL;
        }

        // Refresh a minute early so a request never goes out with a token about to lapse.
        return max(1, $expires - now()->timestamp - 60);
    }

    /**
     * Sends an authenticated GET, re-authenticating once when SASCO rejects a cached token.
     */
    protected function get(FuelProviderConnection $connection, string $endpoint, array $params = []): Response
    {
        $response = $this->client($connection)->get($endpoint, $params);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey($connection));
            $response = $this->client($connection)->get($endpoint, $params);
        }

        return $response;
    }

    protected function paginatedGet(FuelProviderConnection $connection, string $endpoint, string $dataKey, array $params = []): Collection
    {
        $page     = max(1, (int) data_get($params, 'page', 1));
        $pageSize = max(1, (int) data_get($params, 'page_size', 100));
        $maxPages = (int) data_get($params, 'max_pages', 100);
        $query    = collect($params)->except(['page', 'page_size', 'max_pages', 'is_trip'])->all();

        $items = collect();

        do {
            $response = $this->get($connection, $endpoint, array_merge($query, ['Page_size' => $pageSize, 'Current_page' => $page]));

            if (!$response->successful()) {
                throw new \RuntimeException($this->errorMessage($response, "SASCO {$endpoint} request failed."));
            }

            $data = $response->json($dataKey);
            if (!is_array($data)) {
                throw new \RuntimeException("SASCO {$endpoint} returned an invalid response.");
            }
            $items = $items->merge($data);

            $total   = (int) $response->json('totalItems', 0);
            $hasMore = count($data) > 0 && $items->count() < $total;
            $page++;
        } while ($hasMore && $page <= $maxPages);

        if ($hasMore) {
            throw new \RuntimeException('SASCO returned more pages than the sync limit. Choose a shorter date range and retry; the import was not marked complete.');
        }

        return $items;
    }

    protected function errorMessage(Response $response, string $fallback): string
    {
        $message = $response->json('message') ?? $response->json('title') ?? $response->json('error');

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    /**
     * Saudi plates are printed as three letters and up to four digits, e.g. "NXD 1240".
     */
    protected function plateNumber(array $plate): ?string
    {
        $number  = $this->compactIdentifier($plate['plateNumber'] ?? null);
        $letters = collect([$plate['firstPlateLetter'] ?? null, $plate['secondPlateLetter'] ?? null, $plate['thirdPlateLetter'] ?? null])
            ->map(fn ($letter) => $this->compactIdentifier($letter))
            ->filter()
            ->implode('');

        return $this->compactIdentifier(trim($letters . ' ' . $number));
    }

    protected function normalizeTransaction(array $transaction): array
    {
        $plate      = (array) ($transaction['vehiclePlate'] ?? []);
        $providerId = $this->compactIdentifier($transaction['transactionNumber'] ?? null);
        $fallbackId = $this->transactionHash([
            $transaction['createdAt'] ?? null,
            $plate,
            $transaction['stationName'] ?? null,
            $transaction['noOfLitters'] ?? null,
            $transaction['amount'] ?? null,
            $transaction['driverPhone'] ?? null,
        ]);

        return [
            'provider'                => $this->key(),
            'provider_transaction_id' => (string) ($providerId ?: $fallbackId),
            'plate_number'            => $this->plateNumber($plate),
            'trip_number'             => $this->compactIdentifier($transaction['tripId'] ?? null),
            'station_name'            => $this->compactIdentifier($transaction['stationName'] ?? null),
            'transaction_at'          => $this->dateFrom($transaction['createdAt'] ?? null),
            'volume'                  => $transaction['noOfLitters'] ?? null,
            'metric_unit'             => 'l',
            'amount'                  => $this->minorCurrencyUnit($transaction['amount'] ?? null),
            'currency'                => 'SAR',
            'normalized_payload'      => [
                'fuel_type'       => $transaction['fuelType'] ?? null,
                'driver_name'     => $transaction['driverName'] ?? null,
                'driver_phone'    => $transaction['driverPhone'] ?? null,
                'type'            => $transaction['type'] ?? null,
                'period'          => $transaction['period'] ?? null,
                'fuel_image_url'  => $transaction['fuelImageUrl'] ?? null,
            ],
            'raw_payload' => $transaction,
        ];
    }
}
