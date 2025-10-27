<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Telematic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
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
    public int $tries   = 1;
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(Telematic $telematic)
    {
        $this->telematic = $telematic;
        $this->queue     = 'telematics-priority';
    }

    /**
     * Execute the job.
     */
    public function handle(ProviderRegistry $registry): void
    {
        $correlationId = Str::uuid()->toString();

        Log::info('Connection test started', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $this->telematic->uuid,
            'provider'       => $this->telematic->provider,
        ]);

        try {
            $provider    = $registry->resolve($this->telematic->provider);
            $credentials = json_decode(Crypt::decryptString($this->telematic->credentials), true);

            $result = $provider->testConnection($credentials);

            if ($result['success']) {
                $this->telematic->status = 'active';
                $this->telematic->meta   = array_merge($this->telematic->meta ?? [], [
                    'last_connection_test' => now()->toDateTimeString(),
                    'last_test_result'     => 'success',
                ]);
            } else {
                $this->telematic->status = 'error';
                $this->telematic->meta   = array_merge($this->telematic->meta ?? [], [
                    'last_connection_test' => now()->toDateTimeString(),
                    'last_test_result'     => 'failed',
                    'last_error'           => $result['message'],
                ]);
            }

            $this->telematic->save();

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
        return $this->job->getJobId() ?? Str::uuid()->toString();
    }
}
