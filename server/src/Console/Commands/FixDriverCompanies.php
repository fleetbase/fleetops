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
     * @return int
     */
    public function handle()
    {
        $drivers = $this->drivers();

        // Fix these drivers
        foreach ($drivers as $driver) {
            /** @var \Fleetbase\Models\User $user */
            $user = $driver->user;
            if ($user) {
                // Sync email if applicable
                $user->syncProperty('email', $driver);

                // Sync phone if applicable
                $user->syncProperty('phone', $driver);

                // Check if customers user has a customer user record with the company
                $doesntHaveCompanyUser = $this->missingCompanyUser($user->uuid, $driver->company_uuid);
                if ($doesntHaveCompanyUser) {
                    $this->line('Found driver ' . $user->name . ' (' . $user->email . ') which doesnt have correct company assignment.');
                    $company = $this->companyByUuid($driver->company_uuid);
                    if ($company) {
                        $user->assignCompany($company);
                        $this->line('Driver ' . $user->email . ' was assigned to company: ' . $company->name);
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    protected function drivers()
    {
        return Driver::whereHas('user')->whereNotNull('company_uuid')->with(['user'])->get();
    }

    protected function missingCompanyUser(string $userUuid, string $companyUuid): bool
    {
        return CompanyUser::where(['user_uuid' => $userUuid, 'company_uuid' => $companyUuid])->doesntExist();
    }

    protected function companyByUuid(string $companyUuid): ?Company
    {
        return Company::where('uuid', $companyUuid)->first();
    }
}
