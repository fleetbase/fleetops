<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\abort_if')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1; function abort_if($condition, $code = 400, $message = \'\') { if ($condition) { throw new \\RuntimeException($message !== \'\' ? $message : (string) $code); } }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\CustomerController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal CustomerController resetCredentials endpoint against
 * SQLite: missing-customer and mismatched-password rejections, successful
 * password changes with optional credential mail dispatch, and the customer,
 * user, password and payload helper seams.
 */
class FleetOpsInternalCustomerProbe extends CustomerController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsCustomerCredentialsBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    app()->instance('hash', new class {
        public function make($value, array $options = [])
        {
            return 'hashed:' . $value;
        }

        public function check($value, $hashedValue, array $options = [])
        {
            return 'hashed:' . $value === $hashedValue;
        }

        public function needsRehash($hashedValue, array $options = [])
        {
            return false;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

    Illuminate\Support\Facades\Mail::swap(new class {
        public array $sent = [];

        public function to($users)
        {
            return $this;
        }

        public function send($mailable)
        {
            $this->sent[] = $mailable;

            return null;
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    $GLOBALS['fleetopsCustomerMailFake'] = Illuminate\Support\Facades\Mail::getFacadeRoot();

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'  => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'title', 'meta', 'photo_uuid', 'place_uuid', 'slug', '_key'],
        'users'     => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'type', 'status', 'timezone', 'slug', 'username', 'avatar_uuid', 'meta', '_key'],
        'companies' => ['uuid', 'public_id', 'name', 'country'],
        'files'     => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'path', 'disk', 'type', '_key'],
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
    $connection->table('users')->insert(['uuid' => 'user-1', 'public_id' => 'user_custcred1', 'company_uuid' => 'company-1', 'name' => 'Customer User', 'email' => 'cust@example.com', 'type' => 'customer', 'status' => 'active']);
    $connection->table('contacts')->insert(['uuid' => '55555555-5555-4555-8555-555555555555', 'public_id' => 'contact_custcred1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'name' => 'Customer One', 'email' => 'cust@example.com', 'type' => 'customer']);

    return $connection;
}

test('reset credentials rejects missing customers and mismatched passwords', function () {
    fleetopsCustomerCredentialsBoot();
    $controller = new CustomerController();

    $missing = $controller->resetCredentials(Request::create('/x', 'POST', ['password' => 'a', 'password_confirmation' => 'a']));
    expect($missing->getData(true)['error'])->toContain('No customer specified');

    $mismatch = $controller->resetCredentials(Request::create('/x', 'POST', ['customer' => 'contact_custcred1', 'password' => 'a', 'password_confirmation' => 'b']));
    expect($mismatch->getData(true)['error'])->toContain('Passwords do not match');
});

test('reset credentials changes the password and optionally mails credentials', function () {
    $connection = fleetopsCustomerCredentialsBoot();
    $controller = new CustomerController();

    $reset = $controller->resetCredentials(Request::create('/x', 'POST', [
        'customer'              => 'contact_custcred1',
        'password'              => 'newsecret',
        'password_confirmation' => 'newsecret',
    ]));
    expect($reset->getData(true)['status'] ?? '')->toBe('ok')
        ->and($connection->table('users')->value('password'))->toContain('newsecret');

    $mailed = $controller->resetCredentials(Request::create('/x', 'POST', [
        'customer'              => '55555555-5555-4555-8555-555555555555',
        'password'              => 'mailedsecret',
        'password_confirmation' => 'mailedsecret',
        'send_credentials'      => 1,
    ]));
    expect($mailed->getData(true)['status'] ?? '')->toBe('ok')
        ->and($GLOBALS['fleetopsCustomerMailFake']->sent)->not->toBeEmpty();
});

test('customer resolution and payload helpers expose user details', function () {
    fleetopsCustomerCredentialsBoot();
    $probe = new FleetOpsInternalCustomerProbe();

    expect($probe->callHelper('resolveCustomer', Request::create('/x', 'POST', ['customer' => 'contact_custcred1']))->uuid)->toBe('55555555-5555-4555-8555-555555555555')
        ->and($probe->callHelper('findUser', 'user-1')?->public_id)->toBe('user_custcred1')
        ->and(strlen($probe->callHelper('randomPassword')))->toBe(16);

    $customer = Contact::where('uuid', '55555555-5555-4555-8555-555555555555')->first();
    expect($probe->callHelper('resolveCustomerUser', $customer)?->uuid)->toBe('user-1')
        ->and($probe->callHelper('freshCustomer', $customer)->uuid)->toBe('55555555-5555-4555-8555-555555555555');

    $payload = $probe->callHelper('customerPayload', $customer);
    expect($payload['uuid'])->toBe('55555555-5555-4555-8555-555555555555')
        ->and($payload)->toHaveKey('user');
});
