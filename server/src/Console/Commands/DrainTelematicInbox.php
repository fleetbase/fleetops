<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DrainTelematicInbox extends Command
{
    protected $signature   = 'fleetops:drain-telematic-inbox';
    protected $description = 'Recover pending telematic deliveries and finish interrupted sync runs.';

    public function handle(): int
    {
        $registry         = app(TelematicProviderRegistry::class);
        $webhookProviders = [];
        foreach ($registry->all() as $descriptor) {
            if (!data_get($descriptor->metadata, 'telemetry.durable_ingestion', false)) {
                continue;
            }
            try {
                $provider = $registry->resolve($descriptor->key);
                if ($provider instanceof TelemetryProviderInterface
                    && (Configuration::options($provider)['webhooks_enabled'] ?? false)) {
                    $webhookProviders[] = $descriptor->key;
                }
            } catch (\Throwable $e) {
                Log::warning('Telemetry provider configuration unavailable during inbox recovery.', ['provider' => $descriptor->key, 'exception' => class_basename($e)]);
            }
        }
        // Exclude paused webhook sources before applying the dispatch limit, so
        // one paused integration cannot starve polling or other providers.
        $pending = DB::table('telematic_deliveries')->leftJoin('telematics', 'telematics.uuid', '=', 'telematic_deliveries.telematic_uuid')
            ->whereIn('telematic_deliveries.status', ['pending', 'retry', 'processing'])
            ->where('available_at', '<=', now())
            ->where(function ($query) use ($webhookProviders) {
                $query->where('source', '!=', 'webhook')->orWhereIn('telematics.provider', $webhookProviders)->orWhereNull('telematics.uuid');
            });
        $optionsByConnection = [];
        foreach ($pending->orderBy('available_at')->limit(1000)->get(['telematic_deliveries.uuid', 'telematic_deliveries.telematic_uuid']) as $row) {
            try {
                if (!isset($optionsByConnection[$row->telematic_uuid])) {
                    $connection                                = Telematic::withoutGlobalScopes()->where('uuid', $row->telematic_uuid)->first();
                    $optionsByConnection[$row->telematic_uuid] = $connection ? Configuration::forConnection($connection) : [];
                }
            } catch (\InvalidArgumentException) {
                DB::table('telematic_deliveries')->where('uuid', $row->uuid)->update(['status' => 'quarantined', 'error' => 'Provider configuration unavailable; inspect and replay.', 'updated_at' => now()]);
                continue;
            }
            try {
                Queue::dispatch((new ProcessTelematicDelivery($row->uuid))->onQueue($optionsByConnection[$row->telematic_uuid]['ingestion_queue'] ?? 'default'));
            } catch (\Throwable) {
                // A broker outage must not prevent run recovery; retry the next minute.
                break;
            }
        }
        // Retention for deliveries and sync runs is applied by fleetops:prune-telematics-data.
        DB::table('telematic_sync_runs')->where('status', 'fetching')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'incomplete', 'error' => 'Worker interrupted; next scheduled sweep will recover.']);
        DB::table('telematic_sync_runs')->where('status', 'ingesting')->get(['uuid'])->each(fn ($run) => Inbox::finishRun($run->uuid));

        return self::SUCCESS;
    }
}
