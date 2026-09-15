<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Jobs\ProcessAfaqyDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DrainAfaqyInbox extends Command
{
    protected $signature   = 'fleetops:drain-afaqy-inbox';
    protected $description = 'Recover pending AFAQY deliveries and expire retained payloads.';

    public function handle(): int
    {
        $pending = DB::table('afaqy_deliveries')->whereIn('status', ['pending', 'retry', 'processing'])
            ->where('available_at', '<=', now());
        if (!config('telematics.afaqy.webhooks_enabled', false)) {
            $pending->where('source', '!=', 'webhook');
        }
        foreach ($pending->orderBy('available_at')->limit(1000)->get(['uuid']) as $row) {
            try {
                \Fleetbase\FleetOps\Support\Telematics\Afaqy\Queue::dispatch((new ProcessAfaqyDelivery($row->uuid))->onQueue(config('telematics.afaqy.ingestion_queue', 'default')));
            } catch (\Throwable) {
                // A broker outage must not prevent retention cleanup; retry the next minute.
                break;
            }
        }
        // Bound deletes so retention cleanup cannot monopolize the inbox table.
        foreach (['processed' => now()->subHours(config('telematics.afaqy.processed_retention_hours', 24)), 'quarantined' => now()->subDays(config('telematics.afaqy.quarantine_retention_days', 7))] as $status => $cutoff) {
            for ($batch = 0; $batch < 20; $batch++) {
                $ids = DB::table('afaqy_deliveries')->where('status', $status)->where('updated_at', '<', $cutoff)->limit(1000)->pluck('uuid');
                if ($ids->isEmpty()) {
                    break;
                }
                DB::table('afaqy_deliveries')->whereIn('uuid', $ids)->delete();
            }
        }
        DB::table('afaqy_sync_runs')->where('status', 'fetching')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'incomplete', 'error' => 'Worker interrupted; next scheduled sweep will recover.']);
        DB::table('afaqy_sync_runs')->where('status', 'ingesting')->get(['uuid'])->each(fn ($run) => \Fleetbase\FleetOps\Support\Telematics\Afaqy\Inbox::finishRun($run->uuid));
        DB::table('afaqy_sync_runs')->where('updated_at', '<', now()->subDays(7))->delete();

        return self::SUCCESS;
    }
}
