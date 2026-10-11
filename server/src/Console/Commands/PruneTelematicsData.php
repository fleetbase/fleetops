<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Carbon\Carbon;
use Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Applies each company's telematics retention policy.
 *
 * Deletes are hard deletes issued in bounded batches so a large backlog never
 * monopolizes the tables ingestion is writing to; a capped run picks up where
 * it left off on the next schedule tick.
 */
class PruneTelematicsData extends Command
{
    public const TABLES = ['device_events', 'positions', 'telematic_deliveries', 'telematic_sync_runs'];

    public const LOCK = 'fleetops:prune-telematics-data';

    /** Cache key prefix for the per-scope compaction watermark (see pruneDeviceEvents). */
    public const WATERMARK_PREFIX = 'fleetops:prune-telematics-data:compacted:';

    protected $signature = 'fleetops:prune-telematics-data
        {--company= : Only prune data for this company (uuid or public id)}
        {--orphans-only : Only prune data without an existing company or connection}
        {--table=* : Only prune these tables (device_events, positions, telematic_deliveries, telematic_sync_runs)}
        {--batch-size=1000 : Rows per delete or update statement}
        {--max-batches=50 : Maximum batches per table per company per run}
        {--dry-run : Report matching rows without deleting or compacting}
        {--no-lock : Skip process locking}';

    protected $description = 'Prune telematics device events, positions, deliveries and sync runs according to each company\'s retention policy.';

    protected int $batchSize  = 1000;
    protected int $maxBatches = 50;
    protected bool $dryRun    = false;

    /** table => company uuids present in that table whose company no longer exists; resolved once per run. */
    protected array $orphanCompaniesByTable = [];

    public function handle(): int
    {
        $tables = $this->requestedTables();
        if ($tables === null) {
            $this->error('Unknown table. Valid tables: ' . implode(', ', self::TABLES) . '.');

            return self::FAILURE;
        }

        $lock = null;
        if (!$this->option('no-lock')) {
            $lock = Cache::lock(self::LOCK, 840);
            if (!$lock->get()) {
                $this->warn('Another telematics prune run appears to be in progress.');

                return self::SUCCESS;
            }
        }

        try {
            // Queue workers may have cached settings before an administrator saved a new policy.
            RetentionPolicy::flush();
            $this->batchSize  = max(100, min(5000, (int) $this->option('batch-size') ?: 1000));
            $this->maxBatches = max(1, (int) $this->option('max-batches') ?: 50);
            $this->dryRun     = (bool) $this->option('dry-run');

            $only        = ((string) $this->option('company')) ?: null;
            $orphansOnly = (bool) $this->option('orphans-only');
            if ($only && $orphansOnly) {
                $this->error('Choose either --company or --orphans-only.');

                return self::FAILURE;
            }
            $companies = $orphansOnly ? new Collection() : $this->companies($only);
            if ($only && $companies->isEmpty()) {
                $this->error(sprintf('Company [%s] was not found.', $only));

                return self::FAILURE;
            }

            $totals = $this->emptyStats();
            foreach ($companies as $company) {
                $stats  = $this->pruneCompany($company->uuid, $this->policyFor($company->uuid), $tables);
                $totals = $this->mergeStats($totals, $stats);
                $this->report($company->public_id ?: $company->uuid, $stats);
            }
            if (!$only) {
                $stats  = $this->pruneOrphans($this->policyFor(null), $tables);
                $totals = $this->mergeStats($totals, $stats);
                $this->report('orphaned', $stats);
            }

            $this->info(sprintf(
                '%s: deleted=%d compacted=%d batches=%d%s',
                $this->dryRun ? 'Telematics prune dry run' : 'Telematics prune complete',
                $totals['deleted'],
                $totals['compacted'],
                $totals['batches'],
                $totals['capped'] ? ' (capped; more rows remain for the next run)' : ''
            ));

            return self::SUCCESS;
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return string[]|null null when an unknown table was requested
     */
    protected function requestedTables(): ?array
    {
        $requested = array_values(array_filter((array) $this->option('table')));
        if ($requested === []) {
            return self::TABLES;
        }

        return array_diff($requested, self::TABLES) === [] ? array_values(array_unique($requested)) : null;
    }

    protected function companies(?string $only): Collection
    {
        $query = DB::table('companies')->select(['id', 'uuid', 'public_id'])->orderBy('id');
        if ($only) {
            $query->where(fn ($where) => $where->where('uuid', $only)->orWhere('public_id', $only));
        }

        return $query->get();
    }

    protected function policyFor(?string $companyUuid): RetentionPolicy
    {
        return RetentionPolicy::forCompany($companyUuid);
    }

    protected function pruneCompany(string $companyUuid, RetentionPolicy $policy, array $tables): array
    {
        $rows = fn ($query) => $query->where('company_uuid', $companyUuid);

        $telematics = null;
        if (array_intersect(['telematic_deliveries', 'telematic_sync_runs'], $tables) !== []) {
            // Inbox tables carry no company; trashed connections still prune under their company's policy.
            $telematics = DB::table('telematics')->where('company_uuid', $companyUuid)->pluck('uuid')->all();
        }
        $inbox = $telematics ? fn ($query) => $query->whereIn('telematic_uuid', $telematics) : null;

        return $this->prune($rows, $inbox, $policy, $tables, $companyUuid);
    }

    /**
     * Rows whose company or connection no longer exists fall back to the system defaults.
     */
    protected function pruneOrphans(RetentionPolicy $policy, array $tables): array
    {
        $rows  = fn ($query) => $this->scopeOrphanRows($query);
        $inbox = fn ($query) => $query->whereNotExists(function ($connections) use ($query) {
            $connections->selectRaw('1')
                ->from('telematics')
                ->join('companies', 'companies.uuid', '=', 'telematics.company_uuid')
                ->whereColumn('telematics.uuid', $query->from . '.telematic_uuid');
        });

        return $this->prune($rows, $inbox, $policy, $tables, 'orphans');
    }

    /**
     * Rows without a company, plus rows whose company uuid no longer exists. The gone
     * companies are resolved once per table from the distinct company uuids it holds, so
     * every batch ranges over the (company_uuid, created_at) index instead of evaluating
     * a NOT IN subquery against every row.
     */
    protected function scopeOrphanRows($query)
    {
        $table = $query->from;
        $gone  = $this->orphanCompaniesByTable[$table] ??= $this->orphanCompanies($table);

        return $query->where(function ($where) use ($gone) {
            $where->whereNull('company_uuid');
            if ($gone !== []) {
                $where->orWhereIn('company_uuid', $gone);
            }
        });
    }

    /**
     * @return string[]
     */
    protected function orphanCompanies(string $table): array
    {
        return DB::table($table)
            ->whereNotNull('company_uuid')
            ->whereNotIn('company_uuid', DB::table('companies')->select('uuid'))
            ->groupBy('company_uuid')
            ->pluck('company_uuid')
            ->all();
    }

    protected function prune(\Closure $rows, ?\Closure $inbox, RetentionPolicy $policy, array $tables, string $scope): array
    {
        $stats = $this->emptyStats();
        if (in_array('device_events', $tables, true)) {
            $stats['tables']['device_events'] = $this->pruneDeviceEvents($rows, $policy, $scope);
        }
        if (in_array('positions', $tables, true)) {
            $stats['tables']['positions'] = $this->prunePositions($rows, $policy);
        }
        if ($inbox && in_array('telematic_deliveries', $tables, true)) {
            $stats['tables']['telematic_deliveries'] = $this->pruneDeliveries($inbox, $policy);
        }
        if ($inbox && in_array('telematic_sync_runs', $tables, true)) {
            $stats['tables']['telematic_sync_runs'] = $this->pruneSyncRuns($inbox, $policy);
        }
        foreach ($stats['tables'] as $table) {
            $stats['deleted'] += $table['deleted'];
            $stats['compacted'] += $table['compacted'];
            $stats['batches'] += $table['batches'];
            $stats['capped']     = $stats['capped'] || $table['capped'];
        }

        return $stats;
    }

    protected function pruneDeviceEvents(\Closure $rows, RetentionPolicy $policy, string $scope): array
    {
        $stats  = $this->emptyTableStats();
        $cutoff = $policy->cutoff('event_retention_days');
        // Soft-deleted events only grow the table; purge them regardless of age.
        $this->deleteInBatches('device_events', 'id', fn ($query) => $rows($query)->whereNotNull('deleted_at'), $stats, 'deleted_at');
        if ($cutoff) {
            $this->deleteInBatches('device_events', 'id', fn ($query) => $rows($query)->where('created_at', '<', $cutoff->toDateTimeString()), $stats, 'created_at');
        }
        if ($policy->compactsEvents() && ($compactAt = $policy->cutoff('event_compact_after_days'))) {
            // Keep the event (type, severity, location, normalized data) but drop the raw provider blobs.
            // Rows past the delete cutoff are left to the delete sweep rather than rewritten first.
            // Everything older than the watermark was compacted by an earlier complete sweep, so
            // only the rows that aged into the window since then are visited; without it every
            // run re-scanned the whole history looking for blobs that were already gone.
            $watermark = $this->compactionWatermark($scope);
            $since     = $cutoff && (!$watermark || $cutoff->greaterThan($watermark)) ? $cutoff : $watermark;
            $completed = $this->updateInBatches(
                'device_events',
                'id',
                function ($query) use ($rows, $since, $compactAt) {
                    $query = $rows($query)->where('created_at', '<', $compactAt->toDateTimeString())->where(fn ($where) => $where->whereNotNull('payload')->orWhereNotNull('meta'));

                    return $since ? $query->where('created_at', '>=', $since->toDateTimeString()) : $query;
                },
                ['payload' => null, 'meta' => null],
                $stats,
                'created_at'
            );
            if ($completed && !$this->dryRun) {
                $this->rememberCompactionWatermark($scope, $compactAt);
            }
        }

        return $stats;
    }

    protected function compactionWatermark(string $scope): ?Carbon
    {
        try {
            $value = Cache::get(self::WATERMARK_PREFIX . $scope);
        } catch (\Throwable) {
            return null;
        }

        return $value ? Carbon::parse($value) : null;
    }

    protected function rememberCompactionWatermark(string $scope, Carbon $compactedBefore): void
    {
        try {
            Cache::forever(self::WATERMARK_PREFIX . $scope, $compactedBefore->toDateTimeString());
        } catch (\Throwable) {
            // A cache outage only costs the next run a wider scan.
        }
    }

    protected function prunePositions(\Closure $rows, RetentionPolicy $policy): array
    {
        $stats = $this->emptyTableStats();
        $this->deleteInBatches('positions', 'id', fn ($query) => $rows($query)->whereNotNull('deleted_at'), $stats, 'deleted_at');
        if ($cutoff = $policy->cutoff('position_retention_days')) {
            $this->deleteInBatches('positions', 'id', fn ($query) => $rows($query)->where('created_at', '<', $cutoff->toDateTimeString()), $stats, 'created_at');
        }

        return $stats;
    }

    protected function pruneDeliveries(\Closure $inbox, RetentionPolicy $policy): array
    {
        $stats = $this->emptyTableStats();
        foreach (['processed' => 'processed_retention_hours', 'quarantined' => 'quarantine_retention_days'] as $status => $key) {
            if ($cutoff = $policy->cutoff($key)) {
                $this->deleteInBatches('telematic_deliveries', 'uuid', fn ($query) => $inbox($query)->where('status', $status)->where('updated_at', '<', $cutoff->toDateTimeString()), $stats, 'updated_at');
            }
        }

        return $stats;
    }

    protected function pruneSyncRuns(\Closure $inbox, RetentionPolicy $policy): array
    {
        $stats = $this->emptyTableStats();
        if ($cutoff = $policy->cutoff('sync_run_retention_days')) {
            // In-flight runs are recovered by the drain command, never expired here.
            $this->deleteInBatches('telematic_sync_runs', 'uuid', fn ($query) => $inbox($query)->whereNotIn('status', ['fetching', 'ingesting'])->where('updated_at', '<', $cutoff->toDateTimeString()), $stats, 'updated_at');
        }

        return $stats;
    }

    /**
     * Batches are ordered by the column the scope ranges over (the retention indexes lead
     * with company_uuid and that column), so each pick is an index range rather than a
     * primary-key walk across the whole table. Returns true when the scope was drained.
     */
    protected function deleteInBatches(string $table, string $key, \Closure $scope, array &$stats, ?string $orderBy = null): bool
    {
        if ($this->dryRun) {
            $stats['deleted'] += $scope(DB::table($table))->count();

            return true;
        }
        for ($batch = 0; $batch < $this->maxBatches; $batch++) {
            $ids = $scope(DB::table($table))->orderBy($orderBy ?? $key)->limit($this->batchSize)->pluck($key);
            if ($ids->isEmpty()) {
                return true;
            }
            $stats['batches']++;
            $stats['deleted'] += DB::table($table)->whereIn($key, $ids->all())->delete();
        }
        $stats['capped'] = true;

        return false;
    }

    protected function updateInBatches(string $table, string $key, \Closure $scope, array $values, array &$stats, ?string $orderBy = null): bool
    {
        if ($this->dryRun) {
            $stats['compacted'] += $scope(DB::table($table))->count();

            return true;
        }
        for ($batch = 0; $batch < $this->maxBatches; $batch++) {
            $ids = $scope(DB::table($table))->orderBy($orderBy ?? $key)->limit($this->batchSize)->pluck($key);
            if ($ids->isEmpty()) {
                return true;
            }
            $stats['batches']++;
            $stats['compacted'] += DB::table($table)->whereIn($key, $ids->all())->update($values);
        }
        $stats['capped'] = true;

        return false;
    }

    protected function report(string $label, array $stats): void
    {
        foreach ($stats['tables'] as $table => $counts) {
            if (!$counts['deleted'] && !$counts['compacted'] && !$counts['capped']) {
                continue;
            }
            $this->info(sprintf('%s %s: deleted=%d compacted=%d batches=%d%s', $label, $table, $counts['deleted'], $counts['compacted'], $counts['batches'], $counts['capped'] ? ' capped' : ''));
        }
    }

    protected function emptyTableStats(): array
    {
        return ['deleted' => 0, 'compacted' => 0, 'batches' => 0, 'capped' => false];
    }

    protected function emptyStats(): array
    {
        return ['deleted' => 0, 'compacted' => 0, 'batches' => 0, 'capped' => false, 'tables' => []];
    }

    protected function mergeStats(array $totals, array $stats): array
    {
        $totals['deleted'] += $stats['deleted'];
        $totals['compacted'] += $stats['compacted'];
        $totals['batches'] += $stats['batches'];
        $totals['capped']     = $totals['capped'] || $stats['capped'];

        return $totals;
    }
}
