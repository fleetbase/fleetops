<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PollTelematicTelemetry implements ShouldQueue, ShouldBeUnique
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
        if (!$telematic || !Inbox::enabled($telematic)) {
            return;
        }
        $provider = $registry->resolve($telematic->provider);
        if (!$provider instanceof TelemetryProviderInterface) {
            return;
        }
        $options = Configuration::options($provider);
        if (!($options['polling_enabled'] ?? false)) {
            return;
        }
        // Coalesce ticks while a previous sweep is still being ingested.
        if (DB::table('telematic_deliveries')->where('telematic_uuid', $this->telematicUuid)->where('source', 'poll')->whereIn('status', ['pending', 'retry', 'processing'])->exists()) {
            return;
        }
        $lock = Cache::lock('telemetry:poll:' . $this->telematicUuid, 150);
        if (!$lock->get()) {
            return;
        }
        $run = (string) Str::uuid();
        DB::table('telematic_sync_runs')->insert(['uuid' => $run, 'telematic_uuid' => $telematic->uuid, 'status' => 'fetching', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $provider->connect($telematic);
            $cursor  = null;
            $cursors = [];
            $seen    = [];
            $started = microtime(true);
            for ($page = 0; $page < ($options['max_pages'] ?? 100); $page++) {
                if (microtime(true) - $started > 90) {
                    throw new \RuntimeException('Polling time budget exceeded; sweep incomplete.');
                }
                $response = $provider->fetchDevices(['limit' => $options['page_size'] ?? 1000, 'cursor' => $cursor, 'timeout' => 20, 'connect_timeout' => 5]);
                $received = now()->utc();
                $units    = $response['devices'];
                $ids      = array_map(fn ($unit) => $provider->normalizeDevice($unit)['device_id'] ?? null, $units);
                foreach ($ids as $id) {
                    if (!$id || isset($seen[(string) $id])) {
                        throw new \RuntimeException('Repeated or unidentified unit; sweep incomplete.');
                    }
                    $seen[(string) $id] = true;
                }
                foreach (array_chunk($units, max(1, (int) ($options['batch_size'] ?? 100))) as $batch) {
                    $inbox->accept($telematic, $batch, 'poll', $run, $received->toDateTimeString());
                }
                DB::table('telematic_sync_runs')->where('uuid', $run)->update(['pages' => $page + 1, 'units' => count($seen), 'updated_at' => now()]);
                if (!$response['has_more']) {
                    DB::table('telematic_sync_runs')->where('uuid', $run)->update(['status' => 'ingesting', 'updated_at' => now()]);
                    Inbox::finishRun($run);

                    return;
                }
                $next = $response['next_cursor'];
                if ($next === null || isset($cursors[(string) $next]) || (string) $next === (string) $cursor) {
                    throw new \RuntimeException('Non-advancing pagination; sweep incomplete.');
                }
                $cursors[(string) $next] = true;
                $cursor                  = $next;
            }
            throw new \RuntimeException('Maximum page count reached; sweep incomplete.');
        } catch (\Throwable $e) {
            DB::table('telematic_sync_runs')->where('uuid', $run)->update(['status' => 'incomplete', 'error' => $e instanceof TelematicRateLimitExceededException ? 'Rate limited; retry scheduled.' : 'Polling failed; retry scheduled. ' . class_basename($e), 'updated_at' => now()]);
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
