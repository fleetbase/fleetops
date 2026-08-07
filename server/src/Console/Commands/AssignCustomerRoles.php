<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Console\Command;

class AssignCustomerRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:assign-customer-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assigns the Customer role to all customer type contacts.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $customers = $this->customers();

        foreach ($customers as $customer) {
            $user = $this->customerUser($customer);
            if (!$user) {
                $this->createUserForCustomer($customer);
                $user = $this->customerUser($customer);
            }

            try {
                $this->assignCustomerRole($user);
                $this->info($customer->name . ' - Customer: ' . $customer->email . ' has been assigned the Customer role.');
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    protected function customers()
    {
        return Contact::where('type', 'customer')->get();
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

    protected function assignCustomerRole($user): void
    {
        $user->assignSingleRole('Fleet-Ops Customer');
    }
}
