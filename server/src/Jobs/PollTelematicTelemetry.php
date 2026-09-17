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

    // Reservations released while earlier batches drain are not provider failures.
    public int $tries         = 0;
    public int $maxExceptions = 5;
    // Keep every attempt below Laravel's baseline Redis retry_after of 90 seconds.
    public int $timeout          = 80;
    public int $uniqueFor        = 3600;
    public ?string $manualJobId  = null;
    public array $requestOptions = [];
    public ?int $retryDeadline   = null;

    public function __construct(public string $telematicUuid, ?string $manualJobId = null, array $requestOptions = [])
    {
        $this->manualJobId    = $manualJobId;
        $this->requestOptions = $requestOptions;
        $this->retryDeadline  = now()->addMinutes(15)->getTimestamp();
    }

    public function uniqueId(): string
    {
        return $this->telematicUuid;
    }

    public function backoff(): array
    {
        // A scheduled retry holds the uniqueness lease that suppresses minute ticks,
        // so it must not wait longer than the polling interval after a transient failure.
        return $this->manualJobId ? [15, 60, 180, 300] : [15, 30, 60, 60];
    }

    public function retryUntil(): \Illuminate\Support\Carbon
    {
        if ($this->retryDeadline === null) {
            // Older serialized jobs have no deadline property. Anchor their first
            // observed attempt once, rather than extending it on each redelivery.
            $key                 = 'telemetry:poll:retry-deadline:' . ($this->job?->uuid() ?? $this->manualJobId ?? $this->telematicUuid);
            $this->retryDeadline = Cache::remember($key, 86400, fn () => now()->addMinutes(15)->getTimestamp());
        }

        return \Illuminate\Support\Carbon::createFromTimestampUTC($this->retryDeadline);
    }

    public function handle(TelematicProviderRegistry $registry, Inbox $inbox): void
    {
        if (now()->gte($this->retryUntil())) {
            $error = new \Illuminate\Queue\MaxAttemptsExceededException('Telemetry polling retry deadline exceeded.');
            if ($this->job) {
                $this->fail($error);
            } else {
                $this->failed($error);
            }

            return;
        }
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
            if ($this->manualJobId && $this->job) {
                $this->manualProgress(['last_sync_result' => 'retrying', 'last_sync_phase' => 'waiting_for_ingestion']);
                $this->release(15);
            }

            return;
        }
        $lock = Cache::lock('telemetry:poll:' . $this->telematicUuid, 85);
        if (!$lock->get()) {
            if ($this->manualJobId && $this->job) {
                $this->release(15);
            }

            return;
        }
        $run = (string) Str::uuid();
        try {
            $started = microtime(true);
            DB::table('telematic_sync_runs')->insert(['uuid' => $run, 'telematic_uuid' => $telematic->uuid, 'status' => 'fetching', 'created_at' => now(), 'updated_at' => now()]);
            $this->adoptPendingRequest($run);
            $this->manualProgress([
                'last_sync_run_uuid'      => $run, 'last_sync_run_job_id' => $this->manualJobId,
                'last_sync_result'        => 'running', 'last_sync_phase' => 'fetching_inventory',
                'last_sync_fetched_total' => 0, 'last_sync_page_count' => 0,
                'last_sync_linked_total'  => 0, 'last_sync_failed_total' => 0, 'last_sync_error' => null,
            ]);
            $provider->connect($telematic);
            $cursor  = null;
            $cursors = [];
            $seen    = [];
            for ($page = 0; $page < ($options['max_pages'] ?? 100); $page++) {
                $remaining = $this->remainingBudget($started);
                // Large fleet responses can exceed 20 seconds. Allow a configured
                // request timeout without extending the bounded sweep deadline.
                $timeout  = min(max(1, (int) ($options['request_timeout_seconds'] ?? 45)), $remaining);
                $response = $provider->fetchDevices([
                    'limit'             => min(max(1, (int) ($this->requestOptions['limit'] ?? $options['page_size'] ?? 1000)), 1000),
                    'cursor'            => $cursor, 'filters' => $this->requestOptions['filters'] ?? [],
                    'refresh_inventory' => $this->manualJobId !== null && $cursor === null,
                    'timeout'           => $timeout, 'connect_timeout' => min(5, $timeout),
                ]);
                $this->remainingBudget($started);
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
                    $this->remainingBudget($started);
                    $inbox->accept($telematic, $batch, 'poll', $run, $received->toDateTimeString());
                }
                $this->remainingBudget($started);
                DB::table('telematic_sync_runs')->where('uuid', $run)->update(['pages' => $page + 1, 'units' => count($seen), 'updated_at' => now()]);
                $this->manualProgress(['last_sync_fetched_total' => count($seen), 'last_sync_page_count' => $page + 1, 'last_sync_phase' => 'fetching_inventory']);
                if (!$response['has_more']) {
                    DB::table('telematic_sync_runs')->where('uuid', $run)->update(['status' => 'ingesting', 'updated_at' => now()]);
                    $this->manualProgress(['last_sync_phase' => 'ingesting', 'last_sync_inventory_total' => count($seen)]);
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
            $this->manualProgress(['last_sync_result' => 'retrying', 'last_sync_phase' => 'retrying', 'last_sync_error' => 'Polling failed; retry scheduled. ' . class_basename($e)]);
            if ($e instanceof TelematicRateLimitExceededException && $this->job) {
                $this->release($e->context()['retry_after'] ?? 60);

                return;
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function remainingBudget(float $started): int
    {
        $remaining = (int) floor(60 - (microtime(true) - $started));
        if ($remaining < 1) {
            throw new \RuntimeException('Polling time budget exceeded; sweep incomplete.');
        }

        return $remaining;
    }

    /**
     * Bind a manual request that has no active run to this scheduled sweep.
     *
     * Every poll job for a connection shares one unique lease, so while a scheduled sweep
     * runs, the request's own job is either not queued (the request was recorded while
     * the lease was held) or no longer exists (lost before or after an interrupted run).
     */
    private function adoptPendingRequest(string $run): void
    {
        if ($this->manualJobId) {
            return;
        }
        DB::transaction(function () use ($run) {
            $connection = Telematic::withoutGlobalScopes()->where('uuid', $this->telematicUuid)->lockForUpdate()->first();
            $meta       = $connection?->meta ?? [];
            $request    = data_get($meta, 'last_sync_job_id');
            if (!$request || !in_array(data_get($meta, 'last_sync_result'), Inbox::PENDING_REQUEST_RESULTS, true)) {
                return;
            }
            $bound = data_get($meta, 'last_sync_run_uuid');
            if ($bound && DB::table('telematic_sync_runs')->where('uuid', $bound)->whereIn('status', ['fetching', 'ingesting'])->exists()) {
                return;
            }
            $connection->status = 'synchronizing';
            $connection->meta   = array_merge($meta, [
                'last_sync_run_uuid'      => $run, 'last_sync_run_job_id' => $request,
                'last_sync_result'        => 'running', 'last_sync_phase' => 'fetching_inventory',
                'last_sync_fetched_total' => 0, 'last_sync_page_count' => 0,
                'last_sync_linked_total'  => 0, 'last_sync_failed_total' => 0, 'last_sync_error' => null,
                'last_sync_progress_at'   => now()->toDateTimeString(),
            ]);
            $connection->save();
        });
    }

    private function manualProgress(array $attributes): void
    {
        if (!$this->manualJobId) {
            return;
        }
        DB::transaction(function () use ($attributes) {
            $connection = Telematic::withoutGlobalScopes()->where('uuid', $this->telematicUuid)->lockForUpdate()->first();
            if (!$connection || !Inbox::enabled($connection) || data_get($connection->meta, 'last_sync_job_id') !== $this->manualJobId) {
                return;
            }
            $connection->status = 'synchronizing';
            $connection->meta   = array_merge($connection->meta ?? [], $attributes, ['last_sync_progress_at' => now()->toDateTimeString()]);
            $connection->save();
        });
    }

    public function failed(\Throwable $error): void
    {
        if (!$this->manualJobId) {
            return;
        }
        $run = null;
        DB::transaction(function () use ($error, &$run) {
            $connection = Telematic::withoutGlobalScopes()->where('uuid', $this->telematicUuid)->lockForUpdate()->first();
            if (!$connection || !Inbox::enabled($connection) || data_get($connection->meta, 'last_sync_job_id') !== $this->manualJobId) {
                return;
            }
            if (data_get($connection->meta, 'last_sync_run_job_id') === $this->manualJobId) {
                $run = data_get($connection->meta, 'last_sync_run_uuid');
            }
            $connection->status = 'error';
            $connection->meta   = array_merge($connection->meta ?? [], [
                'last_sync_result'     => 'failed', 'last_sync_phase' => 'failed',
                'last_sync_error'      => 'Polling retries exhausted. ' . class_basename($error),
                'last_sync_error_type' => class_basename($error), 'last_sync_failed_at' => now()->toDateTimeString(),
            ]);
            $connection->save();
        });
        // Do not lock run rows while holding the connection lock: finishRun takes
        // those locks in the opposite order when the final delivery completes.
        if ($run) {
            DB::table('telematic_sync_runs')->where('uuid', $run)->where('status', 'fetching')->update(['status' => 'incomplete', 'error' => 'Polling attempt interrupted.', 'updated_at' => now()]);
        }
    }
}
