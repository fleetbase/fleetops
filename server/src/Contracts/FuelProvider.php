<?php

namespace Fleetbase\FleetOps\Contracts;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface FuelProvider
{
    public function key(): string;

    public function name(): string;

    public function authenticate(FuelProviderConnection $connection): array;

    public function testConnection(FuelProviderConnection $connection): array;

    public function listVehicles(FuelProviderConnection $connection, array $options = []): Collection;

    public function listTransactions(FuelProviderConnection $connection, Carbon $from, Carbon $to, array $options = []): Collection;

    public function listStations(FuelProviderConnection $connection, array $options = []): Collection;

    public function pushTrip(FuelProviderConnection $connection, array $trip): array;

    public function syncVehicle(FuelProviderConnection $connection, array $vehicle): array;

    public function webhookPayloadToTransaction(FuelProviderConnection $connection, array $payload): ?array;
}
