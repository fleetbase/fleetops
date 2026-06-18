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
        {--sync-webhook-providers : Include providers that support webhooks}
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
        $requestedProviders = array_filter((array) $this->option('provider'));
        $includeWebhookProviders = (bool) $this->option('sync-webhook-providers');

        return $registry->all()
            ->filter(function ($descriptor) use ($requestedProviders, $includeWebhookProviders) {
                if (!empty($requestedProviders) && !in_array($descriptor->key, $requestedProviders, true)) {
                    return false;
                }

                if (!$descriptor->supportsDiscovery) {
                    return false;
                }

                return $includeWebhookProviders || !$descriptor->supportsWebhooks;
            })
            ->keys()
            ->values()
            ->all();
    }
}
