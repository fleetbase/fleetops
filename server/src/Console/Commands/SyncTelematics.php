<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncTelematics extends Command
{
    protected $signature = 'fleetops:sync-telematics
        {--provider=* : Limit polling to one or more provider keys}
        {--limit=500 : Maximum provider units to fetch per page}
        {--exclude-webhook-providers : Skip providers that support webhooks}
        {--no-lock : Skip process locking}';

    protected $description = 'Poll active telematics providers for device snapshots and positional telemetry.';

    public function handle(TelematicProviderRegistry $registry): int
    {
        $useLock = !$this->option('no-lock');
        $lock    = null;

        if ($useLock) {
            $lock = Cache::lock('fleetops:sync-telematics', 600);
            if (!$lock->get()) {
                $this->warn('Another telematics sync run appears to be in progress.');

                return self::SUCCESS;
            }
        }

        try {
            $providerKeys = $this->pollableProviderKeys($registry);
            if (empty($providerKeys)) {
                $this->info('No pollable telematics providers found.');

                return self::SUCCESS;
            }

            if (in_array('afaqy', $providerKeys, true) && config('telematics.afaqy.polling_enabled', false)) {
                Telematic::withoutGlobalScopes()->where('provider', 'afaqy')->whereIn('status', ['active', 'connected', 'error', 'synchronizing'])
                    ->whereNotNull('company_uuid')->orderBy('id')->chunkById(100, function ($connections) {
                        foreach ($connections as $connection) {
                            if (\Fleetbase\FleetOps\Support\Telematics\Afaqy\Inbox::enabled($connection)) {
                                try {
                                    \Fleetbase\FleetOps\Support\Telematics\Afaqy\Queue::dispatch((new \Fleetbase\FleetOps\Jobs\PollAfaqyTelemetry($connection->uuid))
                                        ->onQueue(config('telematics.afaqy.poll_queue', 'default'))
                                        ->delay(now()->addSeconds(abs(crc32($connection->uuid)) % 10)));
                                } catch (\Throwable) {
                                    \Illuminate\Support\Facades\Log::warning('AFAQY polling dispatch failed; next tick will retry.', ['telematic_uuid' => $connection->uuid]);
                                }
                            }
                        }
                    });
                $providerKeys = array_values(array_diff($providerKeys, ['afaqy']));
            }
            $query = Telematic::withoutGlobalScopes()
                ->whereIn('provider', $providerKeys)
                ->whereIn('status', ['active', 'connected'])
                ->whereNotNull('company_uuid');

            $queued = 0;
            $query->orderBy('id')->chunkById(100, function ($telematics) use (&$queued) {
                foreach ($telematics as $telematic) {
                    SyncTelematicDevicesJob::dispatch($telematic, [
                        'limit' => (int) $this->option('limit'),
                    ]);
                    $queued++;
                }
            });

            $this->info("Queued {$queued} telematics sync job(s).");

            return self::SUCCESS;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    protected function pollableProviderKeys(TelematicProviderRegistry $registry): array
    {
        $requestedProviders      = array_filter((array) $this->option('provider'));
        $excludeWebhookProviders = (bool) $this->option('exclude-webhook-providers');

        return $registry->all()
            ->filter(function ($descriptor) use ($requestedProviders, $excludeWebhookProviders) {
                if (!empty($requestedProviders) && !in_array($descriptor->key, $requestedProviders, true)) {
                    return false;
                }

                if (!$descriptor->supportsDiscovery) {
                    return false;
                }

                return $descriptor->key === 'afaqy' || !$excludeWebhookProviders || !$descriptor->supportsWebhooks;
            })
            ->keys()
            ->values()
            ->all();
    }
}
