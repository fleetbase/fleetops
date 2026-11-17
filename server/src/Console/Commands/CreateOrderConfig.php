<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\Models\Company;
use Illuminate\Console\Command;

class CreateOrderConfig extends Command
{
    protected $signature = 'fleetops:create-order-config
        {template? : The template key, for example transport or passenger}
        {--company= : The company uuid or public id}
        {--name= : Make a new config of this name instead of the company\'s system config for the template}
        {--list : List the templates}';

    protected $description = 'Create an order config for a company from a template';

    public function handle(): int
    {
        if ($this->option('list') || !$this->argument('template')) {
            $this->table(['Key', 'Name', 'Description'], OrderConfigTemplates::describe());

            return self::SUCCESS;
        }

        $key = (string) $this->argument('template');
        if (!OrderConfigTemplates::has($key)) {
            $this->error('No order config template named ' . $key . '. Run with --list to see them.');

            return self::FAILURE;
        }

        $id      = (string) $this->option('company');
        $company = $id !== '' ? Company::where('uuid', $id)->orWhere('public_id', $id)->first() : null;
        if (!$company) {
            $this->error($id === '' ? 'Give the company with --company.' : 'No company found for ' . $id . '.');

            return self::FAILURE;
        }

        $name   = $this->option('name');
        $config = OrderConfigTemplates::create($key, $company, is_string($name) && trim($name) !== '' ? trim($name) : null);

        $this->info(($config->wasRecentlyCreated ? 'Created' : 'Found') . ' order config ' . $config->name . ' (' . $config->public_id . ', key ' . $config->key . ') for ' . $company->name . '.');

        return self::SUCCESS;
    }
}
