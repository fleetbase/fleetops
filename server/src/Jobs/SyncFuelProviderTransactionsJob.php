<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SyncFuelProviderTransactionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $connectionUuid, public ?string $from = null, public ?string $to = null, public array $options = [])
    {
    }

    public function handle(FuelProviderService $fuelProviderService): void
    {
        $connection = FuelProviderConnection::where('uuid', $this->connectionUuid)->firstOrFail();

        try {
            $fuelProviderService->syncTransactions(
                $connection,
                $this->from ? Carbon::parse($this->from) : null,
                $this->to ? Carbon::parse($this->to) : null,
                $this->options
            );
        } catch (\Throwable $e) {
            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_sync_state' => [
                    'failed_at' => now()->toIso8601String(),
                    'message' => $e->getMessage(),
                ],
            ]);

            throw $e;
        }
    }
}
