<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Illuminate\Console\Command;

class FixLegacyOrderConfigs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:fix-legacy-order-configs {--create-configs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a command which migrates legacy orders to the new order config system.';

    /**
     * Execute the console command.
     *
     * This method is responsible for the main logic of the command.
     * It fetches dispatchable orders, notifies about the current time and number of orders,
     * and then dispatches each order if there are nearby drivers.
     *
     * @return int
     */
    public function handle()
    {
        $shouldCreateConfigs = $this->option('create-configs');
        if ($shouldCreateConfigs) {
            $companies      = $this->companies();
            $totalCompanies = $companies->count();
            $this->info('Initializing transport config for ' . $totalCompanies . ' companies.');
            $progressBar = $this->createProgressBar($totalCompanies);
            $progressBar->start();
            foreach ($companies as $company) {
                $this->createTransportConfig($company);
                $progressBar->advance();
            }
            $progressBar->finish();
            $this->line('');
            $this->info('All transport configs created.');
        }

        $orders      = $this->ordersWithoutConfig();
        $totalOrders = $orders->count();
        $this->info($totalOrders . ' orders found for updating.');
        $progressBar = $this->createProgressBar($totalOrders);
        $progressBar->start();
        foreach ($orders as $order) {
            try {
                $orderConfig = $this->transportConfigForCompany($order->company_uuid);
                if ($orderConfig) {
                    $order->update(['order_config_uuid' => $orderConfig->uuid]);
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                $this->error('Order ID: ' . $order->uuid);
                continue;
            }
            // Advance the progress bar by one step
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');
        $this->info('All orders have been processed.');
    }

    protected function companies()
    {
        return Company::all();
    }

    protected function createTransportConfig(Company $company): void
    {
        FleetOps::createTransportConfig($company);
    }

    protected function ordersWithoutConfig()
    {
        return Order::whereNull('order_config_uuid')->get();
    }

    protected function transportConfigForCompany(string $companyUuid): ?OrderConfig
    {
        return OrderConfig::where(['company_uuid' => $companyUuid, 'namespace' => 'system:order-config:transport'])->first();
    }

    protected function createProgressBar(int $total)
    {
        return $this->output->createProgressBar($total);
    }
}
