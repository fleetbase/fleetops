<?php

namespace Fleetbase\FleetOps\Support\FuelProviders\Providers;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PetroAppFuelProvider extends AbstractFuelProvider
{
    public function key(): string
    {
        return 'petroapp';
    }

    public function name(): string
    {
        return 'PetroApp';
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        try {
            $response = $this->client($connection)->get('vehicles', ['page' => 1]);

            if (!$response->successful()) {
                return [
                    'success'  => false,
                    'message'  => $response->json('message') ?? 'Unable to connect to PetroApp.',
                    'metadata' => ['status' => $response->status()],
                ];
            }

            return [
                'success'  => true,
                'message'  => 'PetroApp connection successful.',
                'metadata' => [
                    'total_vehicles' => $response->json('data.total'),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'metadata' => []];
        }
    }

    public function listVehicles(FuelProviderConnection $connection, array $options = []): Collection
    {
        return $this->paginatedGet($connection, 'vehicles', $options);
    }

    public function listStations(FuelProviderConnection $connection, array $options = []): Collection
    {
        return $this->paginatedGet($connection, 'petroapp_locations', $options, 'locs');
    }

    public function listTransactions(FuelProviderConnection $connection, Carbon $from, Carbon $to, array $options = []): Collection
    {
        $params = array_merge($options, [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
            'lang' => data_get($options, 'lang', 'en'),
        ]);

        return $this->paginatedGet($connection, 'bills', $params)
            ->map(fn ($payload) => $this->normalizeBill($payload));
    }

    protected function baseUrl(FuelProviderConnection $connection): string
    {
        $credentials = (array) $connection->credentials;

        return rtrim(data_get($credentials, 'base_url', 'https://app-public.staging.petroapp.app/webservice'), '/');
    }

    protected function headers(FuelProviderConnection $connection): array
    {
        $credentials = (array) $connection->credentials;
        $token       = data_get($credentials, 'api_token') ?? data_get($credentials, 'api_key');

        if (data_get($credentials, 'auth_type') === 'ws_sk_header') {
            return [
                'WS-Version' => data_get($credentials, 'version', 'v2.0'),
                'WS-SK'      => $token,
            ];
        }

        return [
            'WS-Version' => data_get($credentials, 'version', 'v2.0'),
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    protected function paginatedGet(FuelProviderConnection $connection, string $endpoint, array $params = [], ?string $dataKey = null): Collection
    {
        $page     = (int) data_get($params, 'page', 1);
        $maxPages = (int) data_get($params, 'max_pages', 100);
        unset($params['max_pages']);

        $items = collect();

        do {
            $response = $this->client($connection)->get($endpoint, array_merge($params, ['page' => $page]));

            if (!$response->successful()) {
                throw new \RuntimeException($response->json('message') ?? "PetroApp {$endpoint} request failed.");
            }

            $payload = $response->json();
            $data    = $dataKey ? data_get($payload, $dataKey, []) : data_get($payload, 'data.data', []);
            $items   = $items->merge($data);

            $lastPage = (int) data_get($payload, 'data.last_page', $page);
            $hasMore  = data_get($payload, 'data.next_page_url') || $page < $lastPage;
            $page++;
        } while ($hasMore && $page <= $maxPages);

        return $items;
    }

    protected function normalizeBill(array $bill): array
    {
        $transactionAt = $this->dateFrom($bill['bill_date'] ?? $bill['billDate'] ?? null);
        $providerId    = $bill['id'] ?? $bill['bill_id'] ?? null;
        $fallbackId    = $this->transactionHash([
            $bill['bill_date'] ?? $bill['billDate'] ?? null,
            $bill['trip_number'] ?? null,
            $bill['internal_number'] ?? null,
            $bill['structure_number'] ?? null,
            $bill['plate_snum'] ?? null,
            $bill['station_name'] ?? null,
            $bill['num_of_liters'] ?? null,
            $bill['cost'] ?? null,
        ]);

        return [
            'provider'                => $this->key(),
            'provider_transaction_id' => (string) ($providerId ?: $fallbackId),
            'provider_vehicle_id'     => $this->compactIdentifier($bill['vehicle_id'] ?? null),
            'vehicle_card_id'         => $this->compactIdentifier($bill['vehicle_card_id'] ?? $bill['fuel_card_number'] ?? $bill['card_number'] ?? null),
            'internal_number'         => $this->compactIdentifier($bill['internal_number'] ?? null),
            'structure_number'        => $this->compactIdentifier($bill['structure_number'] ?? null),
            'plate_number'            => $this->compactIdentifier($bill['plate_snum'] ?? null),
            'vin'                     => $this->compactIdentifier($bill['vin'] ?? $bill['vin_number'] ?? null),
            'serial_number'           => $this->compactIdentifier($bill['serial_number'] ?? null),
            'call_sign'               => $this->compactIdentifier($bill['call_sign'] ?? null),
            'trip_number'             => $this->compactIdentifier($bill['trip_number'] ?? null),
            'station_name'            => $this->compactIdentifier($bill['station_name'] ?? null),
            'station_latitude'        => $bill['station_lat'] ?? null,
            'station_longitude'       => $bill['station_lng'] ?? null,
            'transaction_at'          => $transactionAt,
            'volume'                  => $bill['num_of_liters'] ?? null,
            'metric_unit'             => 'l',
            'amount'                  => $this->minorCurrencyUnit($bill['cost'] ?? null),
            'currency'                => 'SAR',
            'odometer'                => $bill['odometer'] ?? null,
            'normalized_payload'      => [
                'payment_method'      => $bill['payment_method'] ?? null,
                'payment_method_text' => $bill['payment_method_text'] ?? null,
                'branch_name'         => $bill['branch_name'] ?? null,
                'city'                => $bill['city'] ?? null,
                'district'            => $bill['district'] ?? null,
                'delegate_name'       => $bill['delegate_name'] ?? null,
            ],
            'raw_payload' => $bill,
        ];
    }
}
