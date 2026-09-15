<?php

namespace Fleetbase\FleetOps\Support\Telematics\Afaqy;

use Fleetbase\FleetOps\Jobs\ProcessAfaqyDelivery;
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
        DB::table('afaqy_deliveries')->insert([
            'uuid'         => $id, 'telematic_uuid' => $telematic->uuid, 'run_uuid' => $run,
            'source'       => $source, 'status' => 'pending', 'payload_hash' => hash('sha256', $json),
            'payload'      => Crypt::encryptString($json), 'received_at' => $receivedAt ?? now()->utc()->toDateTimeString(),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The scheduled drain also dispatches pending records if the broker is unavailable.
        try {
            Queue::dispatch((new ProcessAfaqyDelivery($id))->onQueue(config('telematics.afaqy.ingestion_queue', 'default')));
        } catch (\Throwable) {
            // Durable acceptance succeeded. The payload must not be logged here.
        }

        return $id;
    }

    public static function enabled(Telematic $telematic): bool
    {
        return $telematic->provider === 'afaqy' && $telematic->company_uuid
            && in_array($telematic->status, ['active', 'connected', 'error', 'synchronizing'], true)
            && data_get($telematic->meta, 'afaqy_sync_enabled', true) !== false;
    }

    public static function finishRun(?string $id): void
    {
        if (!$id) {
            return;
        }
        DB::transaction(function () use ($id) {
            $run = DB::table('afaqy_sync_runs')->where('uuid', $id)->lockForUpdate()->first();
            if (!$run || $run->status === 'fetching') {
                return;
            }
            $items = DB::table('afaqy_deliveries')->where('run_uuid', $id);
            if ((clone $items)->whereIn('status', ['pending', 'processing', 'retry'])->exists()) {
                return;
            }
            $failed = (clone $items)->sum('failed');
            DB::table('afaqy_sync_runs')->where('uuid', $id)->update([
                'status'  => $run->status === 'incomplete' ? 'incomplete' : ($failed ? 'partial' : 'completed'),
                'applied' => (clone $items)->sum('applied'), 'failed' => $failed, 'updated_at' => now(),
            ]);
        });
    }
}
