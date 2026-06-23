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
use Illuminate\Support\Facades\Cache;
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
    public int $tries          = 1;
    public int $timeout        = 3600;
    public bool $failOnTimeout = true;

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
        $lockKey       = 'fleetops:sync-telematic-devices:' . $this->telematic->uuid;
        $lock          = Cache::lock($lockKey, $this->timeout + 60);

        if (!$lock->get()) {
            Log::info('Device discovery skipped because another sync is already running', [
                'correlation_id' => $correlationId,
                'telematic_uuid' => $this->telematic->uuid,
                'provider'       => $this->telematic->provider,
                'lock_key'       => $lockKey,
            ]);

            $this->telematic->meta = array_merge($this->telematic->meta ?? [], [
                'last_sync_job_id'          => $this->jobId,
                'last_sync_result'          => 'skipped',
                'last_sync_skipped_reason'  => 'sync_already_running',
                'last_sync_skipped_at'      => now()->toDateTimeString(),
            ]);
            $this->telematic->save();

            return;
        }

        try {
            Log::info('Device discovery started', [
                'correlation_id' => $correlationId,
                'telematic_uuid' => $this->telematic->uuid,
                'provider'       => $this->telematic->provider,
            ]);

            $cursor                     = null;
            $totalFetched               = 0;
            $totalLinked                = 0;
            $totalLinkAttempts          = 0;
            $totalEvents                = 0;
            $totalSensors               = 0;
            $totalSkipped               = 0;
            $pageCount                  = 0;
            $lastProviderAllCount       = null;
            $lastProviderFiltersCount   = null;
            $providerSyncMeta           = [];
            $linkedDeviceKeys           = [];
            $inventoryPayloads          = [];
            $inventoryFetched           = 0;
            $inventoryLinked            = 0;
            $inventorySkipped           = 0;
            $totalEnrichment            = 0;
            $totalEnrichmentCompleted   = 0;
            $totalEnrichmentFailures    = 0;

            try {
                $provider = $registry->resolve($this->telematic->provider);
                $provider->connect($this->telematic);

                do {
                    $response = $provider->fetchDevices([
                        'limit'   => $this->options['limit'] ?? null,
                        'cursor'  => $cursor,
                        'filters' => $this->options['filters'] ?? [],
                    ]);

                    $devices          = $response['devices'] ?? [];
                    $pagination       = $response['pagination'] ?? [];
                    $providerSyncMeta = array_merge($providerSyncMeta, $response['sync_meta'] ?? []);
                    $pageCount++;
                    $totalFetched += count($devices);
                    $inventoryPayloads = array_merge($inventoryPayloads, $devices);

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
                            $result = $service->ingestDeviceSnapshot($this->telematic, $provider, $devicePayload);
                            $totalLinkAttempts++;
                            $linkedDeviceKey = $this->resolveLinkedDeviceKey($result, $normalizedDevice);

                            if ($linkedDeviceKey && !isset($linkedDeviceKeys[$linkedDeviceKey])) {
                                $linkedDeviceKeys[$linkedDeviceKey] = true;
                                $totalLinked++;
                            }

                            $totalEvents += count($result['events'] ?? array_filter([$result['event'] ?? null]));
                            $totalSensors += $result['sensors'] ?? 0;
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
                        'link_attempts'  => $totalLinkAttempts,
                        'events'         => $totalEvents,
                        'sensors'        => $totalSensors,
                        'skipped'        => $totalSkipped,
                        'has_more'       => $response['has_more'] ?? false,
                    ]);

                    // Broadcast progress (TODO: implement WebSocket broadcasting)
                } while (($response['has_more'] ?? false) && $cursor);

                $inventoryFetched = $totalFetched;
                $inventoryLinked  = $totalLinked;
                $inventorySkipped = $totalSkipped;

                $this->telematic->status = 'synchronizing';
                $this->telematic->meta   = array_merge($this->telematic->meta ?? [], $providerSyncMeta, [
                    'last_sync_job_id'                   => $this->jobId,
                    'last_sync_result'                   => 'inventory_synced',
                    'last_sync_total'                    => $totalLinked,
                    'last_sync_inventory_completed_at'   => now()->toDateTimeString(),
                    'last_sync_inventory_total'          => $inventoryFetched,
                    'last_sync_inventory_linked_total'   => $inventoryLinked,
                    'last_sync_inventory_skipped_total'  => $inventorySkipped,
                    'last_sync_fetched_total'            => $totalFetched,
                    'last_sync_linked_total'             => $totalLinked,
                    'last_sync_link_attempts_total'      => $totalLinkAttempts,
                    'last_sync_skipped_total'            => $totalSkipped,
                    'last_sync_page_count'               => $pageCount,
                    'last_sync_provider_total'           => $lastProviderFiltersCount ?? $lastProviderAllCount,
                    'last_sync_provider_all_count'       => $lastProviderAllCount,
                    'last_sync_provider_filters_count'   => $lastProviderFiltersCount,
                ]);
                $this->telematic->save();

                Log::info($this->telematic->provider === 'safee' ? 'Safee inventory sync completed' : 'Telematics inventory sync completed', [
                    'correlation_id'   => $correlationId,
                    'telematic_uuid'   => $this->telematic->uuid,
                    'provider'         => $this->telematic->provider,
                    'inventory_total'  => $inventoryFetched,
                    'inventory_linked' => $inventoryLinked,
                    'skipped'          => $inventorySkipped,
                ]);

                if (method_exists($provider, 'fetchDeviceTelemetrySnapshots') && !empty($inventoryPayloads)) {
                    Log::info($this->telematic->provider === 'safee' ? 'Safee telemetry enrichment started' : 'Telematics telemetry enrichment started', [
                        'correlation_id' => $correlationId,
                        'telematic_uuid' => $this->telematic->uuid,
                        'provider'       => $this->telematic->provider,
                        'device_count'   => count($inventoryPayloads),
                    ]);

                    $enrichmentResponse = $provider->fetchDeviceTelemetrySnapshots($inventoryPayloads, [
                        'limit'   => $this->options['limit'] ?? null,
                        'filters' => $this->options['filters'] ?? [],
                    ]);
                    $providerSyncMeta = array_merge($providerSyncMeta, $enrichmentResponse['sync_meta'] ?? []);
                    $enrichedDevices  = $enrichmentResponse['devices'] ?? [];
                    $totalEnrichment += count($enrichedDevices);

                    foreach ($enrichedDevices as $devicePayload) {
                        $normalizedDevice = $provider->normalizeDevice($devicePayload);
                        try {
                            $result = $service->ingestDeviceSnapshot($this->telematic, $provider, $devicePayload);
                            $totalLinkAttempts++;
                            $linkedDeviceKey = $this->resolveLinkedDeviceKey($result, $normalizedDevice);

                            if ($linkedDeviceKey && !isset($linkedDeviceKeys[$linkedDeviceKey])) {
                                $linkedDeviceKeys[$linkedDeviceKey] = true;
                                $totalLinked++;
                            }

                            $totalEnrichmentCompleted++;
                            $totalEvents += count($result['events'] ?? array_filter([$result['event'] ?? null]));
                            $totalSensors += $result['sensors'] ?? 0;
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            $totalSkipped++;
                            $totalEnrichmentFailures++;
                            Log::warning('Skipping telematics enrichment without provider identity', [
                                'correlation_id'   => $correlationId,
                                'provider'         => $this->telematic->provider,
                                'provider_unit_id' => $devicePayload['_id'] ?? $devicePayload['id'] ?? null,
                                'device_id'        => $normalizedDevice['device_id'] ?? null,
                                'name'             => $normalizedDevice['name'] ?? $devicePayload['name'] ?? null,
                                'imei'             => $devicePayload['imei'] ?? null,
                            ]);
                        }
                    }

                    Log::info($this->telematic->provider === 'safee' ? 'Safee telemetry enrichment completed' : 'Telematics telemetry enrichment completed', [
                        'correlation_id' => $correlationId,
                        'telematic_uuid' => $this->telematic->uuid,
                        'provider'       => $this->telematic->provider,
                        'total'          => $totalEnrichment,
                        'completed'      => $totalEnrichmentCompleted,
                        'failures'       => $totalEnrichmentFailures,
                    ]);
                }

                Log::info('Device discovery completed', [
                    'correlation_id'        => $correlationId,
                    'total_fetched'         => $totalFetched,
                    'total_linked'          => $totalLinked,
                    'total_link_attempts'   => $totalLinkAttempts,
                    'total_enrichment'      => $totalEnrichment,
                    'enrichment_completed'  => $totalEnrichmentCompleted,
                    'enrichment_failures'   => $totalEnrichmentFailures,
                    'total_events'          => $totalEvents,
                    'total_sensors'         => $totalSensors,
                    'total_skipped'         => $totalSkipped,
                    'page_count'            => $pageCount,
                    'provider_all_count'    => $lastProviderAllCount,
                    'provider_filter_count' => $lastProviderFiltersCount,
                ]);

                $this->telematic->status = 'active';
                $this->telematic->meta   = array_merge($this->telematic->meta ?? [], $providerSyncMeta, [
                    'last_sync_job_id'                  => $this->jobId,
                    'last_sync_completed_at'            => now()->toDateTimeString(),
                    'last_sync_result'                  => 'success',
                    'last_sync_total'                   => $totalLinked,
                    'last_sync_fetched_total'           => $totalFetched,
                    'last_sync_linked_total'            => $totalLinked,
                    'last_sync_link_attempts_total'     => $totalLinkAttempts,
                    'last_sync_inventory_total'         => $inventoryFetched,
                    'last_sync_inventory_linked_total'  => $inventoryLinked,
                    'last_sync_inventory_skipped_total' => $inventorySkipped,
                    'last_sync_enrichment_total'        => $totalEnrichment,
                    'last_sync_enrichment_completed'    => $totalEnrichmentCompleted,
                    'last_sync_enrichment_failures'     => $totalEnrichmentFailures,
                    'last_sync_events_total'            => $totalEvents,
                    'last_sync_sensors_total'           => $totalSensors,
                    'last_sync_skipped_total'           => $totalSkipped,
                    'last_sync_page_count'              => $pageCount,
                    'last_sync_provider_total'          => $lastProviderFiltersCount ?? $lastProviderAllCount,
                    'last_sync_provider_all_count'      => $lastProviderAllCount,
                    'last_sync_provider_filters_count'  => $lastProviderFiltersCount,
                    'last_sync_error'                   => null,
                    'last_sync_error_context'           => null,
                ]);
                $this->telematic->save();
            } catch (\Exception $e) {
                $failureContext = method_exists($e, 'context') ? $e->context() : [];
                $failureMessage = $this->safeSyncErrorMessage($e);

                Log::error('Device discovery failed', [
                    'correlation_id'   => $correlationId,
                    'error'            => $failureMessage,
                    'exception'        => get_class($e),
                    'provider_context' => $failureContext,
                ]);

                $this->telematic->status = 'error';
                $this->telematic->meta   = array_merge($this->telematic->meta ?? [], [
                    'last_sync_job_id'                  => $this->jobId,
                    'last_sync_result'                  => 'failed',
                    'last_sync_fetched_total'           => $totalFetched,
                    'last_sync_linked_total'            => $totalLinked,
                    'last_sync_link_attempts_total'     => $totalLinkAttempts,
                    'last_sync_inventory_total'         => $inventoryFetched,
                    'last_sync_inventory_linked_total'  => $inventoryLinked,
                    'last_sync_inventory_skipped_total' => $inventorySkipped,
                    'last_sync_enrichment_total'        => $totalEnrichment,
                    'last_sync_enrichment_completed'    => $totalEnrichmentCompleted,
                    'last_sync_enrichment_failures'     => $totalEnrichmentFailures,
                    'last_sync_events_total'            => $totalEvents,
                    'last_sync_sensors_total'           => $totalSensors,
                    'last_sync_skipped_total'           => $totalSkipped,
                    'last_sync_page_count'              => $pageCount,
                    'last_sync_provider_total'          => $lastProviderFiltersCount ?? $lastProviderAllCount,
                    'last_sync_provider_all_count'      => $lastProviderAllCount,
                    'last_sync_provider_filters_count'  => $lastProviderFiltersCount,
                    'last_sync_error'                   => $failureMessage,
                    'last_sync_error_type'              => class_basename($e),
                    'last_sync_error_context'           => $failureContext,
                    'last_sync_failed_at'               => now()->toDateTimeString(),
                ]);
                $this->telematic->save();

                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Get the job ID.
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function failed(\Throwable $e): void
    {
        $this->telematic->refresh();
        $this->telematic->status = 'error';
        $this->telematic->meta   = array_merge($this->telematic->meta ?? [], [
            'last_sync_job_id'        => $this->jobId,
            'last_sync_result'        => 'failed',
            'last_sync_error'         => $this->safeSyncErrorMessage($e),
            'last_sync_error_type'    => class_basename($e),
            'last_sync_failed_at'     => now()->toDateTimeString(),
            'last_sync_failed_reason' => $e instanceof \Illuminate\Queue\TimeoutExceededException ? 'job_timeout' : null,
        ]);
        $this->telematic->save();
    }

    protected function resolveLinkedDeviceKey(array $result, array $normalizedDevice): ?string
    {
        $device = $result['device'] ?? null;
        $value  = null;

        if (is_object($device)) {
            $value = $device->uuid ?? $device->device_id ?? null;
        }

        $value ??= $normalizedDevice['device_id']
            ?? $normalizedDevice['external_id']
            ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected function safeSyncErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (!$message || preg_match('/(token=|password|client_secret|authorization|bearer\s+[a-z0-9._-]+)/i', $message)) {
            return 'Device sync failed. Review the provider connection and safe sync metadata, then try again.';
        }

        return $message;
    }
}
