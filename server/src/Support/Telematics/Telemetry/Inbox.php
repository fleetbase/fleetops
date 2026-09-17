<?php

namespace Fleetbase\FleetOps\Support\Telematics\Telemetry;

use Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery;
use Fleetbase\FleetOps\Models\Telematic;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Inbox
{
    public function accept(Telematic $telematic, array $payload, string $source, ?string $run = null, ?string $receivedAt = null): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $id   = (string) Str::uuid();
        DB::table('telematic_deliveries')->insert([
            'uuid'         => $id, 'telematic_uuid' => $telematic->uuid, 'run_uuid' => $run,
            'source'       => $source, 'status' => 'pending', 'payload_hash' => hash('sha256', $json),
            'payload'      => Crypt::encryptString($json), 'received_at' => $receivedAt ?? now()->utc()->toDateTimeString(),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The scheduled drain also dispatches pending records if the broker is unavailable.
        try {
            Queue::dispatch((new ProcessTelematicDelivery($id))->onQueue(Configuration::forConnection($telematic)['ingestion_queue'] ?? 'default'));
        } catch (\Throwable) {
            // Durable acceptance succeeded. The payload must not be logged here.
        }

        return $id;
    }

    public static function enabled(Telematic $telematic): bool
    {
        return (bool) $telematic->company_uuid
            && in_array($telematic->status, ['active', 'connected', 'error', 'synchronizing'], true)
            && data_get($telematic->meta, 'telemetry_sync_enabled', true) !== false;
    }

    public static function finishRun(?string $id): void
    {
        if (!$id) {
            return;
        }
        DB::transaction(function () use ($id) {
            $run = DB::table('telematic_sync_runs')->where('uuid', $id)->lockForUpdate()->first();
            if (!$run || $run->status === 'fetching') {
                return;
            }
            $items = DB::table('telematic_deliveries')->where('run_uuid', $id);
            if ((clone $items)->whereIn('status', ['pending', 'processing', 'retry'])->exists()) {
                return;
            }
            $failed  = (clone $items)->sum('failed');
            $applied = (clone $items)->sum('applied');
            $status  = $run->status === 'incomplete' ? 'incomplete' : ($failed ? 'partial' : 'completed');
            DB::table('telematic_sync_runs')->where('uuid', $id)->update([
                'status'  => $status,
                'applied' => $applied, 'failed' => $failed, 'updated_at' => now(),
            ]);
            // An incomplete fetch can still have accepted batches, but the poll
            // job owns its retry/failure lifecycle. Draining them must not end the
            // manual request while its next fetch attempt is still queued.
            if ($status === 'incomplete') {
                return;
            }
            // A previous sweep may finish after another manual request was queued.
            // Only the run explicitly associated with that request may finalize it.
            $connection = Telematic::withoutGlobalScopes()->where('uuid', $run->telematic_uuid)->lockForUpdate()->first();
            if (!$connection || !self::enabled($connection)
                || data_get($connection->meta, 'last_sync_run_uuid') !== $id
                || !data_get($connection->meta, 'last_sync_run_job_id')
                || data_get($connection->meta, 'last_sync_run_job_id') !== data_get($connection->meta, 'last_sync_job_id')) {
                return;
            }
            $connection->status = $status === 'completed' ? 'active' : 'error';
            $connection->meta   = array_merge($connection->meta ?? [], [
                'last_sync_result'                                                         => $status === 'completed' ? 'success' : $status,
                'last_sync_phase'                                                          => $status, 'last_sync_progress_at' => now()->toDateTimeString(),
                'last_sync_fetched_total'                                                  => (int) $run->units, 'last_sync_page_count' => (int) $run->pages,
                'last_sync_total'                                                          => (int) $applied, 'last_sync_linked_total' => (int) $applied,
                'last_sync_failed_total'                                                   => (int) $failed,
                'last_sync_error'                                                          => $status === 'completed' ? null : ($run->error ?? 'Some units could not be applied; inspect telemetry diagnostics.'),
                $status === 'completed' ? 'last_sync_completed_at' : 'last_sync_failed_at' => now()->toDateTimeString(),
            ]);
            $connection->save();
        });
    }
}
