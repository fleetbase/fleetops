<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class TestTelematicConnectionJob.
 *
 * Job for testing connection to a provider asynchronously.
 */
class TestTelematicConnectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public Telematic $telematic;
    public string $jobId;
    public int $tries   = 1;
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(Telematic $telematic, ?string $jobId = null)
    {
        $this->telematic = $telematic;
        $this->jobId     = $jobId ?? Str::uuid()->toString();
        $this->queue     = 'telematics-priority';
    }

    /**
     * Execute the job.
     */
    public function handle(TelematicProviderRegistry $registry, TelematicService $service): void
    {
        $correlationId = Str::uuid()->toString();

        Log::info('Connection test started', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $this->telematic->uuid,
            'provider'       => $this->telematic->provider,
        ]);

        try {
            $provider    = $registry->resolve($this->telematic->provider);
            $credentials = $service->getCredentials($this->telematic);

            $result = $provider->testConnection($credentials);

            $service->recordConnectionTest($this->telematic, $result);

            Log::info('Connection test completed', [
                'correlation_id' => $correlationId,
                'success'        => $result['success'],
            ]);

            // Broadcast result (TODO: implement WebSocket broadcasting)
        } catch (\Exception $e) {
            Log::error('Connection test failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            $this->telematic->status = 'error';
            $this->telematic->save();

            throw $e;
        }
    }

    /**
     * Get the job ID.
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }
}
