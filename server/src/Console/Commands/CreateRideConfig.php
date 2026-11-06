<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Illuminate\Console\Command;

class CreateRideConfig extends Command
{
    protected $signature = 'fleetops:create-ride-config
        {company : The company uuid or public id}
        {--emergency-number= : The number the rider app calls in an emergency}
        {--support-phone= : The operator phone number riders call for help}
        {--support-email= : The operator email address riders write to for help}';

    protected $description = 'Create the ride order config for a company, for rider apps such as Passenger';

    public function handle(): int
    {
        $id      = (string) $this->argument('company');
        $company = Company::where('uuid', $id)->orWhere('public_id', $id)->first();
        if (!$company) {
            $this->error('No company found for ' . $id . '.');

            return self::FAILURE;
        }

        $config = FleetOps::createRideConfig($company);

        // What the rider app shows from these settings: see customers/ride-settings.
        $rider = array_filter([
            'emergency_number' => $this->option('emergency-number'),
            'support_phone'    => $this->option('support-phone'),
            'support_email'    => $this->option('support-email'),
        ], fn ($value) => is_string($value) && trim($value) !== '');
        if ($rider) {
            $config->update(['meta' => array_replace_recursive((array) $config->meta, ['rider' => $rider])]);
        }
        $this->info('Ride config ' . $config->public_id . ' is ready for ' . $company->name . '.');

        return self::SUCCESS;
    }
}
