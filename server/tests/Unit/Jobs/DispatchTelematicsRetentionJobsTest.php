<?php

use Fleetbase\FleetOps\Jobs\DispatchTelematicsRetentionJobs;
use Fleetbase\FleetOps\Jobs\PruneTelematicsDataJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Collection;

class FleetOpsTelematicsRetentionDispatchProbe extends DispatchTelematicsRetentionJobs
{
    public array $dispatched  = [];
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
    $job              = new FleetOpsTelematicsRetentionDispatchProbe(200, 25);
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
        $job              = new FleetOpsTelematicsRetentionDispatchProbe(1000);
        $job->companyRows = $companies;
        $job->handle();

        expect($job->dispatched)->toHaveCount(count($companies) + 1);
        $orphans = $job->dispatched[count($companies)];
        expect($orphans)->toBeInstanceOf(PruneTelematicsDataJob::class)
            ->and($orphans->companyUuid)->toBeNull()
            ->and($orphans->orphansOnly)->toBeTrue();
    }
});

test('system cleanup reads all companies in bounded id order and dispatches through the queue', function () {
    $originalDb         = app()->bound('db') ? app('db') : null;
    $originalCache      = Illuminate\Support\Facades\Cache::getFacadeRoot();
    $originalConfig     = config('cache', []);
    $contract           = Illuminate\Contracts\Bus\Dispatcher::class;
    $originalDispatcher = app()->bound($contract) ? app($contract) : null;
    $connection         = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->getSchemaBuilder()->create('companies', function ($table) {
        $table->integer('id');
        $table->string('uuid');
    });
    foreach (array_reverse(range(1, 103)) as $id) {
        $connection->table('companies')->insert(['id' => $id, 'uuid' => 'tenant-' . $id]);
    }
    app()->instance('db', $connection);
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array']]);
    Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\CacheManager(app()));
    $dispatcher = new class(app()) extends Illuminate\Bus\Dispatcher {
        public array $jobs = [];

        public function dispatch($command)
        {
            $this->jobs[] = $command;

            return $command;
        }
    };
    app()->instance($contract, $dispatcher);
    try {
        (new DispatchTelematicsRetentionJobs(1, 7))->handle();
        expect($dispatcher->jobs)->toHaveCount(101)
            ->and($dispatcher->jobs[0]->companyUuid)->toBe('tenant-2')
            ->and($dispatcher->jobs[99]->companyUuid)->toBe('tenant-101')
            ->and($dispatcher->jobs[100]->afterCompanyId)->toBe(101);
        $dispatcher->jobs[100]->handle();
        expect($dispatcher->jobs)->toHaveCount(104)
            ->and($dispatcher->jobs[101]->companyUuid)->toBe('tenant-102')
            ->and($dispatcher->jobs[102]->companyUuid)->toBe('tenant-103')
            ->and($dispatcher->jobs[103]->orphansOnly)->toBeTrue();
    } finally {
        if ($originalDb) {
            app()->instance('db', $originalDb);
        } else {
            app()->forgetInstance('db');
        }
        Illuminate\Support\Facades\DB::clearResolvedInstance('db');
        Illuminate\Support\Facades\Cache::swap($originalCache);
        config(['cache' => $originalConfig]);
        if ($originalDispatcher) {
            app()->instance($contract, $originalDispatcher);
        } else {
            app()->forgetInstance($contract);
        }
    }
});
