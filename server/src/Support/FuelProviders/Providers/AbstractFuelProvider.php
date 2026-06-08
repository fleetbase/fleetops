<?php

namespace Fleetbase\FleetOps\Support\FuelProviders\Providers;

use Fleetbase\FleetOps\Contracts\FuelProvider;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class AbstractFuelProvider implements FuelProvider
{
    public function authenticate(FuelProviderConnection $connection): array
    {
        return ['success' => true, 'message' => 'Authentication headers prepared.'];
    }

    public function listVehicles(FuelProviderConnection $connection, array $options = []): Collection
    {
        return collect();
    }

    public function listStations(FuelProviderConnection $connection, array $options = []): Collection
    {
        return collect();
    }

    public function pushTrip(FuelProviderConnection $connection, array $trip): array
    {
        return ['success' => false, 'message' => 'Provider does not support trip push.'];
    }

    public function syncVehicle(FuelProviderConnection $connection, array $vehicle): array
    {
        return ['success' => false, 'message' => 'Provider does not support vehicle sync.'];
    }

    public function webhookPayloadToTransaction(FuelProviderConnection $connection, array $payload): ?array
    {
        return null;
    }

    protected function client(FuelProviderConnection $connection): PendingRequest
    {
        $credentials = (array) $connection->credentials;

        return Http::baseUrl($this->baseUrl($connection))
            ->timeout((int) data_get($credentials, 'timeout', 30))
            ->acceptJson()
            ->withHeaders($this->headers($connection));
    }

    protected function baseUrl(FuelProviderConnection $connection): string
    {
        $credentials = (array) $connection->credentials;

        return rtrim(data_get($credentials, 'base_url', ''), '/');
    }

    protected function headers(FuelProviderConnection $connection): array
    {
        return [];
    }

    protected function transactionHash(array $payload): string
    {
        return hash('sha256', json_encode($payload));
    }

    protected function minorCurrencyUnit($amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    protected function dateFrom($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value);
    }

    protected function compactIdentifier($value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Str::of($value)->replaceMatches('/\s+/', ' ')->trim()->toString();
    }
}
