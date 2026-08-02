<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Console\Command;

class AssignDriverRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:assign-driver-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assigns the Driver role to all driver users.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $companies = $this->companies();

        foreach ($companies as $company) {
            /** @var Company $company */
            $company->loadMissing('users');
            foreach ($company->users as $user) {
                if ($this->isUser($user)) {
                    $this->setCompanyUserRelation($user, $company);
                    $driver = $this->driverForCompany($user, $company->uuid);
                    if ($driver && $this->isNotAdmin($user)) {
                        try {
                            $this->assignDriverRole($user);
                            $this->info($company->name . ' - Driver: ' . $user->email . ' has been made Driver.');
                        } catch (\Throwable $e) {
                            $this->error($e->getMessage());
                        }
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    protected function companies()
    {
        return Company::with('users', 'users.driver')->get();
    }

    protected function isUser($user): bool
    {
        return $user instanceof User;
    }

    protected function setCompanyUserRelation($user, Company $company): void
    {
        $user->setCompanyUserRelation($company);
    }

    protected function driverForCompany($user, string $companyUuid)
    {
        return $user->driver()->where('company_uuid', $companyUuid)->first();
    }

    protected function isNotAdmin($user): bool
    {
        return $user->isNotAdmin();
    }

    protected function assignDriverRole($user): void
    {
        $user->assignSingleRole('Driver');
    }
}
