<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeUnpurchasedServiceQuotes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:purge-service-quotes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes all unpurchased service quotes that are older than 48 hours.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $thresholdDate = now()->subHours(48);

        $this->disableForeignKeyConstraints();
        $this->beginTransaction();

        try {
            // Get service quotes that are expired and have not been purchased
            $deletedCount = $this->purgeServiceQuotes($thresholdDate);

            $this->commit();

            if ($deletedCount > 0) {
                $this->info("Successfully deleted {$deletedCount} unpurchased service quotes.");
            } else {
                $this->info('No unpurchased service quotes found for deletion.');
            }
        } catch (\Exception $e) {
            $this->rollBack();
            $this->enableForeignKeyConstraints();
            $this->error('Error deleting unpurchased service quotes: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->enableForeignKeyConstraints();

        return Command::SUCCESS;
    }

    protected function disableForeignKeyConstraints(): void
    {
        Schema::disableForeignKeyConstraints();
    }

    protected function enableForeignKeyConstraints(): void
    {
        Schema::enableForeignKeyConstraints();
    }

    protected function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    protected function commit(): void
    {
        DB::commit();
    }

    protected function rollBack(): void
    {
        DB::rollBack();
    }

    protected function purgeServiceQuotes($thresholdDate): int
    {
        return ServiceQuote::where('created_at', '<', $thresholdDate)
            ->whereNotIn('uuid', function ($query) {
                $query->select('service_quote_uuid')->from('purchase_rates')->whereNotNull('service_quote_uuid');
            })
            ->withTrashed()
            ->forceDelete();
    }
}
