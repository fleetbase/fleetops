<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public function middleware(): array
    {
        return [(new WithoutOverlapping('fuel-provider:' . $this->connectionUuid))->releaseAfter(30)->expireAfter(1800)];
    }

    public function handle(FuelProviderService $fuelProviderService): void
    {
        $connection = $this->findConnection();
        $syncRun    = $this->findSyncRun();

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
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $error): void
    {
        // Laravel calls this for terminal failures, including worker timeouts
        // that never reach the catch block in handle().
        $this->findSyncRun()?->update([
            'status'      => 'error',
            'finished_at' => now(),
            'error'       => 'The background import stopped before completing. Retry this date range.',
        ]);
        FuelProviderConnection::where('uuid', $this->connectionUuid)->update([
            'status'     => 'error',
            'last_error' => 'The background import stopped before completing. Retry this date range.',
        ]);
    }

    protected function findConnection(): FuelProviderConnection
    {
        return FuelProviderConnection::where('uuid', $this->connectionUuid)->firstOrFail();
    }

    protected function findSyncRun(): ?FuelProviderSyncRun
    {
        return $this->syncRunUuid ? FuelProviderSyncRun::where('uuid', $this->syncRunUuid)->first() : null;
    }
}
