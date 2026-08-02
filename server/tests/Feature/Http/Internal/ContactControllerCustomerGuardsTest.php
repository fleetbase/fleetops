<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ContactController;
use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal ContactController customer guard seams: the
 * before-update hook resolving user input and asserting customer identity,
 * the identity assertion replicating contacts for dry-run validation, and
 * the portal welcome email failing when no login can be provisioned.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

class FleetOpsContactCustomerGuardsProbe extends ContactController
{
    public bool $portalInstalled = true;

    public function callProtected(string $method, ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ContactController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function isCustomerPortalInstalled(): bool
    {
        return $this->portalInstalled;
    }

    protected function createCustomerUserFromContact(Contact $contact): ?Fleetbase\Models\User
    {
        return null;
    }
}

function fleetopsContactCustomerGuardsBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'      => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'title', 'internal_id', 'meta', '_key'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'status', 'password', '_key'],
        'companies'     => ['uuid', 'public_id', 'name', 'options'],
        'company_users' => ['uuid', 'company_uuid', 'user_uuid', 'status'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);

    return $connection;
}

test('before update resolves user input and asserts customer identity', function () {
    $connection = fleetopsContactCustomerGuardsBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-guard-1', 'public_id' => 'contact_guardone1', 'company_uuid' => 'company-1', 'name' => 'Guarded Customer', 'email' => 'guarded@example.test', 'type' => 'customer']);

    $contact = Contact::where('uuid', 'contact-guard-1')->first();
    $probe   = new FleetOpsContactCustomerGuardsProbe();

    // No user input: resolution returns early and identity stays available
    $input = ['type' => 'customer', 'name' => 'Guarded Customer'];
    $probe->callProtected('onBeforeUpdate', Request::create('/int/v1/contacts/contact-guard-1', 'PUT'), $contact, $input);
    expect($input)->toHaveKey('type', 'customer');

    // Customer contacts cannot switch types
    $badInput = ['type' => 'contact'];
    expect(function () use ($probe, $contact, &$badInput) {
        $probe->callProtected('onBeforeUpdate', Request::create('/int/v1/contacts/contact-guard-1', 'PUT'), $contact, $badInput);
    })->toThrow(Exception::class, 'Customer contact type cannot be changed.');

    // The static identity assertion also validates fresh customer input
    $freshInput = ['type' => 'customer', 'name' => 'New Customer', 'company_uuid' => 'company-1', 'email' => 'fresh@example.test'];
    $probe->callProtected('assertCustomerIdentityIsAvailable', $freshInput, null);
    $probe->callProtected('assertCustomerIdentityIsAvailable', ['type' => 'vendor'], null);
    expect(true)->toBeTrue();
});

test('portal welcome email fails when no customer login can be provisioned', function () {
    $connection = fleetopsContactCustomerGuardsBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-guard-2', 'public_id' => 'contact_guardtwo2', 'company_uuid' => 'company-1', 'name' => 'Portal Customer', 'email' => 'portal@example.test', 'type' => 'customer', 'meta' => json_encode(['customer_portal' => ['send_welcome_email' => true]])]);

    $contact = Contact::where('uuid', 'contact-guard-2')->first();
    $probe   = new FleetOpsContactCustomerGuardsProbe();

    expect(function () use ($probe, $contact) {
        $probe->callProtected('sendCustomerPortalWelcomeEmail', $contact);
    })->toThrow(Exception::class, 'Unable to create customer portal login.');
});
