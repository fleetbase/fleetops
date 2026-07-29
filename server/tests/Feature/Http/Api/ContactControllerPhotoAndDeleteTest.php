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

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController;
use Fleetbase\FleetOps\Http\Requests\CreateContactRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the API ContactController against SQLite: contact creation and
 * updates resolving photos through the file resolver (including the REMOVE
 * key), deletion cascading to related users, and the place lookup seam.
 */
class FleetOpsApiContactProbe extends ContactController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsContactPhotoBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

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
    app()->instance('cache', new class {
        public function tags($tags = null)
        {
            return $this;
        }

        public function flush()
        {
            return true;
        }

        public function remember($key, $ttl, $callback)
        {
            return $callback();
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');

    app()->instance(Fleetbase\Services\FileResolverService::class, new class {
        public function resolve($photo, $path)
        {
            return (object) ['uuid' => 'file-contact-1'];
        }
    });

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'  => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'title', 'meta', 'photo_uuid', 'place_uuid', 'internal_id', 'slug', '_key'],
        'users'     => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'type', 'status', 'slug', 'username', 'meta', '_key'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'location', '_key'],
        'companies' => ['uuid', 'public_id', 'name'],
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

    return $connection;
}

test('contacts create and update resolve photos and removal keys', function () {
    $connection = fleetopsContactPhotoBoot();
    $controller = new ContactController();

    $created = $controller->create(CreateContactRequest::create('/v1/contacts', 'POST', [
        'name'  => 'Photo Contact',
        'email' => 'photo@example.com',
        'type'  => 'contact',
        'photo' => 'data:image/png;base64,' . base64_encode('img'),
    ]));
    expect($connection->table('contacts')->where('name', 'Photo Contact')->value('photo_uuid'))->toBe('file-contact-1');

    $publicId = $connection->table('contacts')->value('public_id');

    // Updates re-resolve new photos
    $controller->update($publicId, UpdateContactRequest::create('/v1/contacts/' . $publicId, 'PUT', [
        'photo' => 'data:image/png;base64,' . base64_encode('img2'),
    ]));
    expect($connection->table('contacts')->value('photo_uuid'))->toBe('file-contact-1');

    // The REMOVE key clears the stored photo
    $controller->update($publicId, UpdateContactRequest::create('/v1/contacts/' . $publicId, 'PUT', [
        'photo' => 'REMOVE',
    ]));
    expect($connection->table('contacts')->value('photo_uuid'))->toBeNull();
});

test('contact deletion cascades to related users and seams resolve places', function () {
    $connection = fleetopsContactPhotoBoot();
    $connection->table('users')->insert(['uuid' => 'user-c1', 'company_uuid' => 'company-1', 'name' => 'Contact User', 'email' => 'linked@example.com', 'type' => 'contact']);
    $connection->table('contacts')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'contact_delone1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-c1', 'name' => 'Deletable', 'email' => 'linked@example.com', 'type' => 'contact']);
    $connection->table('places')->insert(['uuid' => '77777777-7777-4777-8777-777777777777', 'public_id' => 'place_contact1', 'company_uuid' => 'company-1', 'name' => 'Contact HQ']);

    $controller = new ContactController();

    $deleted = $controller->delete('contact_delone1');
    expect($connection->table('contacts')->whereNull('deleted_at')->count())->toBe(0)
        ->and($connection->table('users')->whereNull('deleted_at')->count())->toBe(0);

    $probe = new FleetOpsApiContactProbe();
    expect($probe->callHelper('getPlaceUuid', 'places', ['public_id' => 'place_contact1']))->toBe('77777777-7777-4777-8777-777777777777');
});

class FleetOpsApiContactThrowingProbe extends ContactController
{
    protected function findRelatedUser(Contact $contact): ?Fleetbase\Models\User
    {
        throw new Exception('related user lookup failed');
    }
}

test('contact update and delete surface identity and lookup failures', function () {
    $connection = fleetopsContactPhotoBoot();
    $connection->table('users')->insert(['uuid' => 'user-conflict-1', 'company_uuid' => 'company-1', 'name' => 'Conflicting Admin', 'email' => 'conflict@example.com', 'type' => 'admin']);
    $connection->table('contacts')->insert(['uuid' => '66666666-6666-4666-8666-666666666671', 'public_id' => 'contact_conflict1', 'company_uuid' => 'company-1', 'name' => 'Conflicted Customer', 'email' => 'original@example.com', 'type' => 'customer']);

    // Updating a customer onto a non-customer user identity fails loudly
    $controller = new ContactController();
    $response   = $controller->update('contact_conflict1', UpdateContactRequest::create('/v1/contacts/contact_conflict1', 'PUT', [
        'email' => 'conflict@example.com',
    ]));
    expect($response)->toBeInstanceOf(Illuminate\Http\JsonResponse::class)
        ->and(json_encode($response->getData(true)))->toContain('error');

    // Delete failures from related-user lookups surface as api errors
    $connection->table('contacts')->insert(['uuid' => '66666666-6666-4666-8666-666666666672', 'public_id' => 'contact_faildel1', 'company_uuid' => 'company-1', 'name' => 'Fail Delete', 'type' => 'contact']);
    $throwing = new FleetOpsApiContactThrowingProbe();
    $failed   = $throwing->delete('contact_faildel1');
    expect(json_encode($failed->getData(true)))->toContain('related user lookup failed');
});
