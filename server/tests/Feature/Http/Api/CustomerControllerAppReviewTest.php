<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
use Fleetbase\FleetOps\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Customer as CustomerResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Support\CustomerAppReview;
use Fleetbase\FleetOps\Support\NavigatorReview;
use Fleetbase\Models\Company;
use Fleetbase\Support\AppReview;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * App Review sign-in for customer apps through the customer API (`/v1/customers`), against
 * SQLite with core's real App Review access: the review number is never texted, a send is
 * refused while the organization's access is off, and the organization's code signs in as its
 * review customer through verify-code and create, never as a new customer. A wrong code and
 * another organization's code are refused.
 */
class FleetOpsCustomerAppReviewTwilioFake
{
    public array $messages = [];

    public function message($to, $text, $mediaUrls = [], $params = [])
    {
        $this->messages[] = [$to, $text];

        return (object) ['sid' => 'SM-test-sid'];
    }
}

/**
 * Swap in a container that answers environment() and hasDebugModeEnabled(), which the bare
 * container of this harness does not implement. Mirrors fleetopsDriverAuthContainer().
 */
function fleetopsCustomerAppReviewContainer(): void
{
    $current = Illuminate\Container\Container::getInstance();
    if (method_exists($current, 'hasDebugModeEnabled')) {
        return;
    }

    $replacement = new class extends Illuminate\Container\Container {
        public function environment(...$environments)
        {
            if (empty($environments)) {
                return 'testing';
            }

            $checks = is_array($environments[0]) ? $environments[0] : $environments;

            return in_array('testing', $checks, true);
        }

        public function hasDebugModeEnabled()
        {
            return true;
        }
    };

    foreach (['bindings', 'instances', 'aliases', 'abstractAliases', 'resolved', 'extenders', 'tags', 'contextual', 'scopedInstances', 'reboundCallbacks', 'globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
        if (!property_exists(Illuminate\Container\Container::class, $property)) {
            continue;
        }
        $reflection = new ReflectionProperty(Illuminate\Container\Container::class, $property);
        $reflection->setAccessible(true);
        if ($reflection->isInitialized($current)) {
            $reflection->setValue($replacement, $reflection->getValue($current));
        }
    }

    Illuminate\Container\Container::setInstance($replacement);
    Illuminate\Support\Facades\Facade::setFacadeApplication($replacement);
}

/**
 * Two organizations, each with an existing review customer (one is created on first sign-in
 * in production; see ProfileAccountManagerTest), a real customer, the review_accesses table,
 * a hasher that really checks, and both Navigator and the customer apps registered on Company.
 */
function fleetopsCustomerAppReviewBoot(): SQLiteConnection
{
    fleetopsCustomerAppReviewContainer();

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
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

    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            return password_hash((string) $value, PASSWORD_BCRYPT);
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return is_string($hashedValue) && password_verify((string) $value, $hashedValue);
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }

        public function verifyConfiguration($value): bool
        {
            return true;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');
    app()->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'                  => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'type', 'status', 'avatar_uuid'],
        'contacts'               => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'place_uuid', 'photo_uuid', 'name', 'title', 'email', 'phone', 'type', 'meta'],
        'companies'              => ['uuid', 'public_id', 'name', 'options'],
        'verification_codes'     => ['uuid', 'public_id', 'subject_uuid', 'subject_type', 'for', 'code', 'meta', 'status', 'expires_at'],
        'personal_access_tokens' => ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at'],
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
    $schema->create('review_accesses', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_type');
        $table->string('subject_uuid');
        $table->boolean('enabled')->default(false);
        $table->string('code')->nullable();
        $table->timestamp('code_generated_at')->nullable();
        $table->timestamp('enabled_at')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    app()->forgetInstance('twilio');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');

    // Navigator first, as the service provider registers them: typeOf(Company) finds Navigator,
    // so these pass only if every customer app call names its own type.
    NavigatorReview::register();
    CustomerAppReview::register();

    session(['company' => 'company-1']);

    $connection->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_one', 'name' => 'Acme'],
        ['uuid' => 'company-2', 'public_id' => 'company_two', 'name' => 'Globex'],
    ]);
    foreach (['company-1', 'company-2'] as $company) {
        $connection->table('users')->insert(['uuid' => 'reviewer-' . $company, 'company_uuid' => $company, 'name' => 'App Reviewer', 'phone' => CustomerAppReview::PHONE, 'type' => 'customer', 'status' => 'active']);
        $connection->table('contacts')->insert(['uuid' => 'review-customer-' . $company, 'public_id' => 'contact_review' . substr($company, -1), 'company_uuid' => $company, 'user_uuid' => 'reviewer-' . $company, 'name' => 'App Reviewer', 'phone' => CustomerAppReview::PHONE, 'type' => 'customer']);
    }
    $connection->table('users')->insert(['uuid' => 'user-real', 'company_uuid' => 'company-1', 'name' => 'Real Customer', 'phone' => '+6591234567', 'type' => 'customer', 'status' => 'active']);
    $connection->table('contacts')->insert(['uuid' => 'customer-real', 'public_id' => 'contact_real', 'company_uuid' => 'company-1', 'user_uuid' => 'user-real', 'name' => 'Real Customer', 'phone' => '+6591234567', 'type' => 'customer']);

    return $connection;
}

function fleetopsCustomerAppReviewOn(string $company): string
{
    [, $code] = CustomerAppReview::update(Company::where('uuid', $company)->first(), true);

    return $code;
}

function fleetopsCustomerAppReviewTwilio(): FleetOpsCustomerAppReviewTwilioFake
{
    $twilio = new FleetOpsCustomerAppReviewTwilioFake();
    app()->instance('twilio', $twilio);
    Fleetbase\Twilio\Support\Laravel\Facade::clearResolvedInstances();

    return $twilio;
}

afterEach(function () {
    AppReview::forget(CustomerAppReview::TYPE);
    AppReview::forget(NavigatorReview::TYPE);
});

test('with customer app review access on, a code request to the review number answers as sent and texts nothing', function () {
    $connection = fleetopsCustomerAppReviewBoot();
    $twilio     = fleetopsCustomerAppReviewTwilio();
    fleetopsCustomerAppReviewOn('company-1');
    $controller = new CustomerController();

    $requested = $controller->requestCode(Request::create('/x', 'POST', ['phone' => '+1 (202) 555-0145']));
    $login     = $controller->loginWithPhone(Request::create('/x', 'POST', ['phone' => '12025550145']));

    expect($requested->getStatusCode())->toBe(200)
        ->and($requested->getData(true))->toBe(['status' => 'ok'])
        ->and($login->getData(true))->toBe(['status' => 'ok', 'method' => 'sms'])
        ->and($twilio->messages)->toBe([])
        ->and($connection->table('verification_codes')->count())->toBe(0)
        // Navigator's access for the same organization is untouched.
        ->and(NavigatorReview::isEnabledFor(Company::where('uuid', 'company-1')->first()))->toBeFalse();
});

test('with customer app review access off, the review number is refused with what to turn on, and nothing is texted', function () {
    $connection = fleetopsCustomerAppReviewBoot();
    $twilio     = fleetopsCustomerAppReviewTwilio();
    $code       = fleetopsCustomerAppReviewOn('company-1');
    CustomerAppReview::update(Company::where('uuid', 'company-1')->first(), false);
    $controller = new CustomerController();

    $requested = $controller->requestCode(Request::create('/x', 'POST', ['phone' => '+12025550145']));
    $login     = $controller->loginWithPhone(Request::create('/x', 'POST', ['phone' => '+12025550145']));
    $verified  = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $code]));

    foreach ([$requested, $login] as $response) {
        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['error'])->toBe(CustomerAppReview::OFF_MESSAGE);
    }
    expect(CustomerAppReview::OFF_MESSAGE)->toContain('Fleet-Ops → Settings → Customer Apps')
        ->and($twilio->messages)->toBe([])
        ->and($verified->getData(true)['error'])->toBe('Invalid verification code.')
        ->and($connection->table('personal_access_tokens')->count())->toBe(0);
});

test('verify-code with the organization code signs in as its review customer with a token, never as a new customer', function () {
    $connection = fleetopsCustomerAppReviewBoot();
    $code       = fleetopsCustomerAppReviewOn('company-1');

    $resource = (new CustomerController())->verifyCode(Request::create('/x', 'POST', ['identity' => '12025550145', 'code' => $code]));

    expect($resource)->toBeInstanceOf(CustomerResource::class)
        ->and($resource->resource->uuid)->toBe('review-customer-company-1')
        ->and($resource->resource->token)->not->toBeEmpty()
        ->and($connection->table('personal_access_tokens')->value('name'))->toBe('review-customer-company-1')
        ->and($connection->table('personal_access_tokens')->value('tokenable_id'))->not->toBeNull();
});

test('create with the organization code signs in as its review customer, whatever name the app sent', function () {
    $connection = fleetopsCustomerAppReviewBoot();
    $code       = fleetopsCustomerAppReviewOn('company-1');

    $request  = CreateCustomerRequest::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $code, 'name' => 'Apple Reviewer']);
    $resource = (new CustomerController())->create($request);
    // The signup path of verify-code leads to the same sign-in.
    $signup = (new CustomerController())->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $code, 'for' => 'fleetops_create_customer', 'name' => 'Apple Reviewer']));

    expect($resource)->toBeInstanceOf(CustomerResource::class)
        ->and($resource->resource->uuid)->toBe('review-customer-company-1')
        ->and($resource->resource->token)->not->toBeEmpty()
        ->and($signup->resource->uuid)->toBe('review-customer-company-1')
        ->and($connection->table('contacts')->count())->toBe(3)
        ->and($connection->table('users')->count())->toBe(3)
        ->and($connection->table('contacts')->where('uuid', 'review-customer-company-1')->value('name'))->toBe('App Reviewer');
});

test('a wrong code, another organization code, or Navigator code does not sign the customer review number in', function () {
    $connection = fleetopsCustomerAppReviewBoot();
    $codeOne    = fleetopsCustomerAppReviewOn('company-1');
    do {
        [, $codeTwo] = CustomerAppReview::update(Company::where('uuid', 'company-2')->first(), true, true);
    } while ($codeTwo === $codeOne);
    do {
        [, $navigatorCode] = AppReview::update(Company::where('uuid', 'company-1')->first(), true, true, null, NavigatorReview::TYPE);
    } while ($navigatorCode === $codeOne);
    $wrong      = $codeOne === '000000' ? '000001' : '000000';
    $controller = new CustomerController();

    $crossed   = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $codeTwo]));
    $guessed   = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $wrong]));
    $navigator = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $navigatorCode]));
    $created   = $controller->create(CreateCustomerRequest::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $codeTwo, 'name' => 'Apple Reviewer']));
    // The organization's code on another number signs nobody in.
    $other = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '+6591234567', 'code' => $codeOne]));

    expect($crossed->getData(true)['error'])->toBe('Invalid verification code.')
        ->and($guessed->getData(true)['error'])->toBe('Invalid verification code.')
        ->and($navigator->getData(true)['error'])->toBe('Invalid verification code.')
        ->and($created->getData(true)['error'])->toBe('Invalid verification code provided.')
        ->and($other->getData(true)['error'])->toBe('Invalid verification code.')
        ->and($connection->table('personal_access_tokens')->count())->toBe(0);

    // At its own organization the second code signs in as that organization's review customer.
    session(['company' => 'company-2']);
    $resource = (new CustomerController())->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $codeTwo]));
    expect($resource->resource->uuid)->toBe('review-customer-company-2');
});

test('orders the review customer places are marked app_review, and real customers orders are not', function () {
    fleetopsCustomerAppReviewBoot();
    $meta = (new ReflectionMethod(CustomerController::class, 'customerOrderMeta'))->getClosure(new CustomerController());

    $review = Contact::withoutGlobalScopes()->where('uuid', 'review-customer-company-1')->first();
    $real   = Contact::withoutGlobalScopes()->where('uuid', 'customer-real')->first();

    expect($meta($review, ['note' => 'x']))->toBe(['note' => 'x', 'app_review' => true])
        ->and($meta($real, ['note' => 'x']))->toBe(['note' => 'x'])
        ->and(CustomerAppReview::isReviewCustomer(null))->toBeFalse()
        ->and(CustomerAppReview::isReviewCustomer(new Contact()))->toBeFalse()
        ->and(CustomerAppReview::isReviewPhone(null))->toBeFalse()
        ->and(CustomerAppReview::isReviewPhone('+1 202 555 0144'))->toBeFalse();
});

test('the customer apps keep their own number, code and access next to Navigator on the same organization', function () {
    fleetopsCustomerAppReviewBoot();
    $company = Company::where('uuid', 'company-1')->first();
    $code    = fleetopsCustomerAppReviewOn('company-1');

    expect(CustomerAppReview::status($company))->toMatchArray(['type' => 'fleetops-customer', 'phone' => '+12025550145', 'phone_display' => '+1 202 555 0145', 'enabled' => true, 'has_code' => true])
        ->and(AppReview::status($company))->toMatchArray(['type' => 'navigator', 'enabled' => false])
        ->and(CustomerAppReview::acceptsCode($company, '+12025550145', $code))->toBeTrue()
        ->and(NavigatorReview::acceptsCode($company, NavigatorReview::PHONE, $code))->toBeFalse()
        ->and(AppReview::definition(CustomerAppReview::TYPE)['permission'])->toBe('fleet-ops update customer-app-settings')
        ->and(AppReview::definition(CustomerAppReview::TYPE)['view_permission'])->toBe('fleet-ops view customer-app-settings');
});

test('a signup code request to the review number answers as sent while access is on, and is refused while it is off', function () {
    fleetopsCustomerAppReviewBoot();
    $twilio = fleetopsCustomerAppReviewTwilio();
    fleetopsCustomerAppReviewOn('company-1');
    $controller = new CustomerController();
    $signup     = fn () => $controller->requestCreationCode(VerifyCreateCustomerRequest::create('/x', 'POST', ['mode' => 'sms', 'identity' => '+1 202 555 0145']));

    $on = $signup();
    CustomerAppReview::update(Company::where('uuid', 'company-1')->first(), false);
    $off = $signup();

    expect($on->getStatusCode())->toBe(200)
        ->and($on->getData(true))->toBe(['status' => 'ok'])
        ->and($off->getStatusCode())->toBe(422)
        ->and($off->getData(true)['error'])->toBe(CustomerAppReview::OFF_MESSAGE)
        ->and($twilio->messages)->toBe([]);
});

test('a review customer whose account cannot be found is told sign-in failed, and gets no token', function () {
    $connection = fleetopsCustomerAppReviewBoot();
    $code       = fleetopsCustomerAppReviewOn('company-1');
    $controller = new class extends CustomerController {
        protected function findUserByUuid(string $uuid): ?Fleetbase\Models\User
        {
            return null;
        }
    };

    $response = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '+12025550145', 'code' => $code]));

    expect($response->getData(true)['error'])->toBe('Unable to sign in the App Review customer.')
        ->and($connection->table('personal_access_tokens')->count())->toBe(0);
});
