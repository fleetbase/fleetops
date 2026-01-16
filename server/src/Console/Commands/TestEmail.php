<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Mail\CustomerCredentialsMail;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:test-email {email} {--type=customer_credentials : The type of email to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test FleetOps email templates';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        $this->info('Sending test email...');
        $this->info("Type: {$type}");
        $this->info("To: {$email}");

        try {
            switch ($type) {
                case 'customer_credentials':
                    $this->sendCustomerCredentialsEmail($email);
                    break;

                default:
                    $this->error("Unknown email type: {$type}");
                    return Command::FAILURE;
            }

            $this->info('✓ Test email sent successfully!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to send test email: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Send a test customer credentials email.
     *
     * @param string $email
     * @return void
     */
    private function sendCustomerCredentialsEmail(string $email): void
    {
        // Create a mock user
        $user = new User([
            'name' => 'Test Customer',
            'email' => $email,
        ]);

        // Create a mock company
        $company = new Company([
            'name' => 'Test Company',
            'public_id' => 'test_company_123',
        ]);

        // Create a mock customer
        $customer = new Contact([
            'name' => 'Test Customer',
            'email' => $email,
            'phone' => '+1234567890',
        ]);

        // Set relations
        $customer->setRelation('company', $company);
        $customer->setRelation('user', $user);

        // Mock password
        $plaintextPassword = 'TestPassword123!';

        // Send the email
        Mail::to($email)->send(new CustomerCredentialsMail($plaintextPassword, $customer));
    }
}
