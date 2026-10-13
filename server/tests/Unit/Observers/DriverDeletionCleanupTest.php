<?php

if (!function_exists('Fleetbase\\Observers\\event')) {
    // The webhook observer announces each deletion; only the cleanup matters here
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

// The lifecycle event the webhook observer builds names the model with a core-api macro
if (!Illuminate\Support\Str::hasMacro('humanize')) {
    Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
}

use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\FleetOps\Listeners\HandleUserRemovedFromCompany;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Observers\CompanyUserObserver;
use Fleetbase\FleetOps\Observers\DriverObserver;
use Fleetbase\FleetOps\Observers\UserObserver;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;

/**
 * Drivers removed along with their user or company membership go through
 * Driver::delete(), so DriverObserver unassigns their orders and releases
 * their managed login account.
 */
beforeEach(function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'co-1']);
});

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
    EloquentModel::unsetEventDispatcher();
});

function driverDeletionCleanupDatabase(): SQLiteConnection
{
    $db     = TrackingPageDatabase::boot();
    $schema = $db->getSchemaBuilder();

    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    // Model hooks bind to the dispatcher present when a class boots
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();
    Driver::observe(DriverObserver::class);
    // Core models clear the HTTP response cache on writes, and log activity
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    TrackingPageDatabase::insert($db, 'users', ['uuid' => 'user-1', 'type' => 'driver']);
    TrackingPageDatabase::insert($db, 'users', ['uuid' => 'user-2', 'type' => 'driver']);
    TrackingPageDatabase::insert($db, 'company_users', ['uuid' => 'cu-1', 'company_uuid' => 'co-1', 'user_uuid' => 'user-1', 'status' => 'active']);
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-1', 'company_uuid' => 'co-1', 'user_uuid' => 'user-1']);
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-2', 'company_uuid' => 'co-1', 'user_uuid' => 'user-2']);
    TrackingPageDatabase::insert($db, 'orders', ['uuid' => 'order-1', 'company_uuid' => 'co-1', 'driver_assigned_uuid' => 'driver-1']);
    TrackingPageDatabase::insert($db, 'orders', ['uuid' => 'order-2', 'company_uuid' => 'co-1', 'driver_assigned_uuid' => 'driver-2']);

    return $db;
}

function driverDeletionCleanupState(SQLiteConnection $db): array
{
    return [
        'drivers' => $db->table('drivers')->whereNull('deleted_at')->orderBy('uuid')->pluck('uuid')->all(),
        'orders'  => $db->table('orders')->orderBy('uuid')->pluck('driver_assigned_uuid', 'uuid')->all(),
        'users'   => $db->table('users')->whereNull('deleted_at')->orderBy('uuid')->pluck('uuid')->all(),
    ];
}

test('deleting a user deletes their drivers through the model so their orders are unassigned', function () {
    $db = driverDeletionCleanupDatabase();
    TrackingPageDatabase::insert($db, 'drivers', ['uuid' => 'driver-1-other', 'company_uuid' => 'co-2', 'user_uuid' => 'user-1']);

    // The user is already soft-deleted when its deleted event fires
    $db->table('users')->where('uuid', 'user-1')->update(['deleted_at' => now()]);
    $user = User::withTrashed()->where('uuid', 'user-1')->first();

    (new UserObserver())->deleted($user);

    expect(driverDeletionCleanupState($db))->toBe([
        'drivers' => ['driver-2'],
        'orders'  => ['order-1' => null, 'order-2' => 'driver-2'],
        'users'   => ['user-2'],
    ]);
});

test('deleting a company user deletes its driver through the model and releases the managed account', function () {
    $db = driverDeletionCleanupDatabase();

    $companyUser = CompanyUser::where('uuid', 'cu-1')->first();
    $companyUser->delete();

    (new CompanyUserObserver())->deleted($companyUser);

    expect(driverDeletionCleanupState($db))->toBe([
        'drivers' => ['driver-2'],
        'orders'  => ['order-1' => null, 'order-2' => 'driver-2'],
        'users'   => ['user-2'],
    ]);
});

test('removing a user from a company deletes its driver through the model', function () {
    $db = driverDeletionCleanupDatabase();

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'co-1'], true);

    (new HandleUserRemovedFromCompany())->handle(new UserRemovedFromCompany(User::where('uuid', 'user-1')->first(), $company));

    expect(driverDeletionCleanupState($db))->toBe([
        'drivers' => ['driver-2'],
        'orders'  => ['order-1' => null, 'order-2' => 'driver-2'],
        'users'   => ['user-2'],
    ]);
});
