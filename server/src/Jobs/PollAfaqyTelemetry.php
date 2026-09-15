<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Inbox;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PollAfaqyTelemetry implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries     = 5;
    public int $timeout   = 120;
    public int $uniqueFor = 3600;

    public function __construct(public string $telematicUuid)
    {
    }

    public function uniqueId(): string
    {
        return $this->telematicUuid;
    }

    public function backoff(): array
    {
        return [15, 60, 180, 300];
    }

    public function handle(TelematicProviderRegistry $registry, Inbox $inbox): void
    {
        $telematic = Telematic::withoutGlobalScopes()->where('uuid', $this->telematicUuid)->first();
        if (!$telematic || !Inbox::enabled($telematic) || !config('telematics.afaqy.polling_enabled', false)) {
            return;
        }
        // Coalesce ticks while a previous sweep is still being ingested.
        if (DB::table('afaqy_deliveries')->where('telematic_uuid', $this->telematicUuid)->where('source', 'poll')->whereIn('status', ['pending', 'retry', 'processing'])->exists()) {
            return;
        }
        $lock = Cache::lock('afaqy:poll:' . $this->telematicUuid, 150);
        if (!$lock->get()) {
            return;
        }
        $run = (string) Str::uuid();
        DB::table('afaqy_sync_runs')->insert(['uuid' => $run, 'telematic_uuid' => $telematic->uuid, 'status' => 'fetching', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $provider = $registry->resolve('afaqy');
            $provider->connect($telematic);
            $cursor  = null;
            $seen    = [];
            $started = microtime(true);
            for ($page = 0; $page < config('telematics.afaqy.max_pages', 100); $page++) {
                if (microtime(true) - $started > 90) {
                    throw new \RuntimeException('Polling time budget exceeded; sweep incomplete.');
                }
                $response = $provider->fetchDevices(['limit' => 1000, 'cursor' => $cursor, 'timeout' => 20, 'connect_timeout' => 5]);
                $received = now()->utc();
                $units    = $response['devices'];
                $ids      = array_map(fn ($unit) => $unit['_id'] ?? $unit['id'] ?? null, $units);
                foreach ($ids as $id) {
                    if (!$id || isset($seen[(string) $id])) {
                        throw new \RuntimeException('Repeated or unidentified unit; sweep incomplete.');
                    }
                    $seen[(string) $id] = true;
                }
                foreach (array_chunk($units, 100) as $batch) {
                    $inbox->accept($telematic, $batch, 'poll', $run, $received->toDateTimeString());
                }
                DB::table('afaqy_sync_runs')->where('uuid', $run)->update(['pages' => $page + 1, 'units' => count($seen), 'updated_at' => now()]);
                if (!$response['has_more']) {
                    DB::table('afaqy_sync_runs')->where('uuid', $run)->update(['status' => 'ingesting', 'updated_at' => now()]);
                    Inbox::finishRun($run);

                    return;
                }
                $next = $response['next_cursor'];
                if ($next === null || (int) $next <= (int) $cursor) {
                    throw new \RuntimeException('Non-advancing pagination; sweep incomplete.');
                }
                $cursor = $next;
            }
            throw new \RuntimeException('Maximum page count reached; sweep incomplete.');
        } catch (\Throwable $e) {
            DB::table('afaqy_sync_runs')->where('uuid', $run)->update(['status' => 'incomplete', 'error' => $e instanceof TelematicRateLimitExceededException ? 'Rate limited; retry scheduled.' : 'Polling failed; retry scheduled. ' . class_basename($e), 'updated_at' => now()]);
            if ($e instanceof TelematicRateLimitExceededException && $this->job) {
                $this->release($e->context()['retry_after'] ?? 60);

                return;
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
