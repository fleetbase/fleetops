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

/**
 * Class SyncTelematicDevicesJob.
 *
 * Job for discovering and syncing devices from a provider.
 */
class SyncTelematicDevicesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public Telematic $telematic;
    public array $options;
    public string $jobId;
    public int $tries   = 3;
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(Telematic $telematic, array $options = [], ?string $jobId = null)
    {
        $this->telematic = $telematic;
        $this->options   = $options;
        $this->jobId     = $jobId ?? \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Execute the job.
     */
    public function handle(TelematicProviderRegistry $registry, TelematicService $service): void
    {
        $correlationId = \Illuminate\Support\Str::uuid()->toString();

        Log::info('Device discovery started', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $this->telematic->uuid,
            'provider'       => $this->telematic->provider,
        ]);

        $cursor                     = null;
        $totalFetched               = 0;
        $totalLinked                = 0;
        $totalSkipped               = 0;
        $pageCount                  = 0;
        $lastProviderAllCount       = null;
        $lastProviderFiltersCount   = null;

        try {
            $provider = $registry->resolve($this->telematic->provider);
            $provider->connect($this->telematic);

            do {
                $response = $provider->fetchDevices([
                    'limit'   => $this->options['limit'] ?? null,
                    'cursor'  => $cursor,
                    'filters' => $this->options['filters'] ?? [],
                ]);

                $devices     = $response['devices'] ?? [];
                $pagination  = $response['pagination'] ?? [];
                $pageCount++;
                $totalFetched += count($devices);

                if (isset($pagination['allCount'])) {
                    $lastProviderAllCount = $pagination['allCount'];
                }

                if (isset($pagination['filtersCount'])) {
                    $lastProviderFiltersCount = $pagination['filtersCount'];
                }

                Log::info('Device discovery page fetched', [
                    'correlation_id' => $correlationId,
                    'telematic_uuid' => $this->telematic->uuid,
                    'provider'       => $this->telematic->provider,
                    'page'           => $pageCount,
                    'cursor'         => $cursor,
                    'limit'          => $pagination['limit'] ?? ($this->options['limit'] ?? null),
                    'result_count'   => count($devices),
                    'all_count'      => $lastProviderAllCount,
                    'filters_count'  => $lastProviderFiltersCount,
                    'next_cursor'    => $response['next_cursor'] ?? null,
                    'has_more'       => $response['has_more'] ?? false,
                ]);

                foreach ($devices as $devicePayload) {
                    $normalizedDevice = $provider->normalizeDevice($devicePayload);
                    try {
                        $service->linkDevice($this->telematic, $normalizedDevice);
                        $totalLinked++;
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        $totalSkipped++;
                        Log::warning('Skipping telematics device without provider identity', [
                            'correlation_id'   => $correlationId,
                            'provider'         => $this->telematic->provider,
                            'provider_unit_id' => $devicePayload['_id'] ?? $devicePayload['id'] ?? null,
                            'device_id'        => $normalizedDevice['device_id'] ?? null,
                            'name'             => $normalizedDevice['name'] ?? $devicePayload['name'] ?? null,
                            'imei'             => $devicePayload['imei'] ?? null,
                        ]);
                    }
                }

                $cursor = $response['next_cursor'] ?? null;

                Log::info('Device discovery progress', [
                    'correlation_id' => $correlationId,
                    'fetched'        => $totalFetched,
                    'linked'         => $totalLinked,
                    'skipped'        => $totalSkipped,
                    'has_more'       => $response['has_more'] ?? false,
                ]);

                // Broadcast progress (TODO: implement WebSocket broadcasting)
            } while (($response['has_more'] ?? false) && $cursor);

            Log::info('Device discovery completed', [
                'correlation_id'        => $correlationId,
                'total_fetched'         => $totalFetched,
                'total_linked'          => $totalLinked,
                'total_skipped'         => $totalSkipped,
                'page_count'            => $pageCount,
                'provider_all_count'    => $lastProviderAllCount,
                'provider_filter_count' => $lastProviderFiltersCount,
            ]);

            $this->telematic->status = 'active';
            $this->telematic->meta   = array_merge($this->telematic->meta ?? [], [
                'last_sync_job_id'                 => $this->jobId,
                'last_sync_completed_at'           => now()->toDateTimeString(),
                'last_sync_result'                 => 'success',
                'last_sync_total'                  => $totalLinked,
                'last_sync_fetched_total'          => $totalFetched,
                'last_sync_linked_total'           => $totalLinked,
                'last_sync_skipped_total'          => $totalSkipped,
                'last_sync_page_count'             => $pageCount,
                'last_sync_provider_total'         => $lastProviderFiltersCount ?? $lastProviderAllCount,
                'last_sync_provider_all_count'     => $lastProviderAllCount,
                'last_sync_provider_filters_count' => $lastProviderFiltersCount,
                'last_sync_error'                  => null,
            ]);
            $this->telematic->save();
        } catch (\Exception $e) {
            Log::error('Device discovery failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
                'exception'      => get_class($e),
            ]);

            $this->telematic->status = 'error';
            $this->telematic->meta   = array_merge($this->telematic->meta ?? [], [
                'last_sync_job_id'                 => $this->jobId,
                'last_sync_result'                 => 'failed',
                'last_sync_fetched_total'          => $totalFetched,
                'last_sync_linked_total'           => $totalLinked,
                'last_sync_skipped_total'          => $totalSkipped,
                'last_sync_page_count'             => $pageCount,
                'last_sync_provider_total'         => $lastProviderFiltersCount ?? $lastProviderAllCount,
                'last_sync_provider_all_count'     => $lastProviderAllCount,
                'last_sync_provider_filters_count' => $lastProviderFiltersCount,
                'last_sync_error'                  => 'Device sync failed. Review the provider connection and server logs, then try again.',
                'last_sync_error_type'             => class_basename($e),
                'last_sync_failed_at'              => now()->toDateTimeString(),
            ]);
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
