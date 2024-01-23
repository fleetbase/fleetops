<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Illuminate\Console\Command;

class FixDriverCompanies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:fix-driver-companies';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a command which checks driver\'s users to make sure they are assigned to company, if not it assigns the user to the drivers company';

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
        $drivers = Driver::whereHas('user', function ($query) {
            $query->whereNull('company_uuid');
            $query->withTrashed();
        })->with(['user'])->get();

        // Fix these drivers
        foreach ($drivers as $driver) {
            $user = $driver->user;
            if ($user) {
                $this->line('Found user ' . $user->name . ' (' . $user->email . ') which has no company assigned');
                // Get company from driver profile
                $company = Company::where('uuid', $driver->company_uuid)->first();
                if ($company) {
                    $user->assignCompany($company);

                    // create company user
                    CompanyUser::create([
                        'user_uuid'    => $user->uuid,
                        'company_uuid' => $company->uuid,
                        'status'       => 'active',
                    ]);

                    // Inform
                    $this->line('User ' . $user->email . ' was assigned to company: ' . $company->name);
                }
            }
        }
    }
}
