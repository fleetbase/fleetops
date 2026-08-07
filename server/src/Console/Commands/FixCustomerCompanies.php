<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Console\Command;

class FixCustomerCompanies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:fix-customer-companies';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a command which checks customer\'s users to make sure they are assigned to company, if not it assigns the user to the drivers company';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $customers = $this->customers();

        // Fix these customers
        foreach ($customers as $customer) {
            /** @var User $user */
            $user = $this->customerUser($customer);
            if (!$user) {
                try {
                    $this->createUserForCustomer($customer);
                    $this->info('User created for customer (' . $customer->name . ' - ' . $customer->email . ')');
                    $user = $this->customerUser($customer);
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    $this->error('Existing user: ' . $customer->email);
                    // Assign existing user to the contact/customer
                    $existingUser = $this->userByEmail($customer->email);
                    if ($existingUser) {
                        $this->assignExistingUserToCustomer($customer, $existingUser);
                        $this->info('Update customer user to existing user of the same email address.');
                        $user = $existingUser;
                    }
                }
            }

            if ($user) {
                // Sync email if applicable
                $user->syncProperty('email', $customer);

                // Sync phone if applicable
                $user->syncProperty('phone', $customer);

                // Check if customers user has a customer user record with the company
                $doesntHaveCompanyUser = $this->missingCompanyUser($user->uuid, $customer->company_uuid);
                if ($doesntHaveCompanyUser) {
                    $this->line('Found user ' . $user->name . ' (' . $user->email . ') which doesnt have correct company assignment.');
                    $company = $this->companyByUuid($customer->company_uuid);
                    if ($company) {
                        $user->assignCompany($company);
                        $this->line('User ' . $user->email . ' was assigned to company: ' . $company->name);
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    protected function customers()
    {
        return Contact::where('type', 'customer')->whereNotNull('company_uuid')->get();
    }

    protected function customerUser(Contact $customer)
    {
        $customer->loadMissing('user');

        return $customer->user;
    }

    protected function createUserForCustomer(Contact $customer)
    {
        return $customer->createUser();
    }

    protected function assignExistingUserToCustomer(Contact $customer, $existingUser): void
    {
        $customer->updateQuietly(['user_uuid' => $existingUser->uuid]);
        $customer->setRelation('user', $existingUser);
    }

    protected function userByEmail(string $email)
    {
        return User::where('email', $email)->first();
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
