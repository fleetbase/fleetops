<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fan out system-wide cleanup in small pages, without a session or membership scope.
 * Each company has its own bounded prune job so one large backlog cannot prevent
 * other organizations from being processed within a shared worker timeout.
 */
class DispatchTelematicsRetentionJobs implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PAGE_SIZE = 100;

    public int $uniqueFor = 900;
    public int $tries     = 3;
    public int $timeout   = 60;

    public function __construct(public int $afterCompanyId = 0, public int $maxBatches = 200)
    {
        $this->onQueue(config('telematics.telemetry.ingestion_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'after-company:' . $this->afterCompanyId;
    }

    public function handle(): void
    {
        $companies = $this->companies();
        foreach ($companies as $company) {
            $this->queueJob(new PruneTelematicsDataJob($company->uuid, $this->maxBatches));
        }

        if ($companies->count() === self::PAGE_SIZE) {
            $this->queueJob(new self((int) $companies->last()->id, $this->maxBatches));

            return;
        }

        $this->queueJob(new PruneTelematicsDataJob(null, $this->maxBatches, true));
    }

    protected function companies(): Collection
    {
        return DB::table('companies')
            ->where('id', '>', $this->afterCompanyId)
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get(['id', 'uuid']);
    }

    protected function queueJob(ShouldBeUnique $job): void
    {
        Queue::dispatch($job);
    }
}
