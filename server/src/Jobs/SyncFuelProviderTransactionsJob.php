<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
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

    public function __construct(public string $connectionUuid, public ?string $from = null, public ?string $to = null, public array $options = [], public ?string $syncRunUuid = null)
    {
    }

    public function handle(FuelProviderService $fuelProviderService): void
    {
        $connection = FuelProviderConnection::where('uuid', $this->connectionUuid)->firstOrFail();
        $syncRun    = $this->syncRunUuid ? FuelProviderSyncRun::where('uuid', $this->syncRunUuid)->first() : null;

        try {
            $fuelProviderService->syncTransactions(
                $connection,
                $this->from ? Carbon::parse($this->from) : null,
                $this->to ? Carbon::parse($this->to) : null,
                $this->options,
                $syncRun
            );
        } catch (\Throwable $e) {
            $syncRun?->update([
                'status'      => 'error',
                'finished_at' => now(),
                'error'       => $e->getMessage(),
            ]);
            $connection->update([
                'status'          => 'error',
                'last_error'      => $e->getMessage(),
                'last_sync_state' => [
                    'failed_at' => now()->toIso8601String(),
                    'message'   => $e->getMessage(),
                ],
            ]);

            throw $e;
        }
    }
}
