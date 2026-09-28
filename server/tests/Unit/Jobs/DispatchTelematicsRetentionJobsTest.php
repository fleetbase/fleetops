<?php

use Fleetbase\FleetOps\Jobs\DispatchTelematicsRetentionJobs;
use Fleetbase\FleetOps\Jobs\PruneTelematicsDataJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Collection;

class FleetOpsTelematicsRetentionDispatchProbe extends DispatchTelematicsRetentionJobs
{
    public array $dispatched = [];
    public array $companyRows = [];

    protected function companies(): Collection
    {
        return collect($this->companyRows);
    }

    protected function queueJob(ShouldBeUnique $job): void
    {
        $this->dispatched[] = $job;
    }
}

test('system cleanup fans out a bounded page and continues from its last company', function () {
    $job = new FleetOpsTelematicsRetentionDispatchProbe(200, 25);
    $job->companyRows = array_map(fn ($id) => (object) ['id' => $id, 'uuid' => 'company-' . $id], range(201, 300));
    $job->handle();

    $prunes = array_slice($job->dispatched, 0, 100);
    expect($prunes)->toHaveCount(100)
        ->and(array_map(fn ($prune) => $prune->companyUuid, $prunes))->toBe(array_map(fn ($id) => 'company-' . $id, range(201, 300)));
    foreach ($prunes as $prune) {
        expect($prune)->toBeInstanceOf(PruneTelematicsDataJob::class)
            ->and($prune->maxBatches)->toBe(25)
            ->and($prune->orphansOnly)->toBeFalse();
    }
    $nextPage = $job->dispatched[100];
    expect($nextPage)->toBeInstanceOf(DispatchTelematicsRetentionJobs::class)
        ->and($nextPage->afterCompanyId)->toBe(300)
        ->and($nextPage->maxBatches)->toBe(25)
        ->and($nextPage->uniqueId())->not->toBe($job->uniqueId())
        ->and($nextPage->queue)->toBe(config('telematics.telemetry.ingestion_queue', 'default'));
});

test('the final system cleanup page queues an orphan pass even when there are no companies', function () {
    foreach ([[], [(object) ['id' => 1001, 'uuid' => 'company-1001']]] as $companies) {
        $job = new FleetOpsTelematicsRetentionDispatchProbe(1000);
        $job->companyRows = $companies;
        $job->handle();

        expect($job->dispatched)->toHaveCount(count($companies) + 1);
        $orphans = $job->dispatched[count($companies)];
        expect($orphans)->toBeInstanceOf(PruneTelematicsDataJob::class)
            ->and($orphans->companyUuid)->toBeNull()
            ->and($orphans->orphansOnly)->toBeTrue();
    }
});
