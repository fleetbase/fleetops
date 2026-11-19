<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PushAppController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\PushAppTest;
use Fleetbase\FleetOps\Support\PushAppDevices;
use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Push\PushOutcome;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;

/**
 * Push apps in Fleet-Ops: drivers' and customers' devices split between the install-wide
 * channels and their push app, devices linked to an app when they register, and Settings →
 * Push apps.
 */
class FleetOpsPushAppsEncrypter implements Illuminate\Contracts\Encryption\Encrypter, Illuminate\Contracts\Encryption\StringEncrypter
{
    public function encrypt($value, $serialize = true)
    {
        return 'enc:' . base64_encode($serialize ? serialize($value) : (string) $value);
    }

    public function decrypt($payload, $unserialize = true)
    {
        $value = base64_decode(substr((string) $payload, 4), true);

        return $unserialize ? unserialize($value) : $value;
    }

    public function encryptString($value)
    {
        return $this->encrypt($value, false);
    }

    public function decryptString($payload)
    {
        return $this->decrypt($payload, false);
    }

    public function getKey()
    {
        return 'test-key';
    }

    public function getAllKeys()
    {
        return ['test-key'];
    }

    public function getPreviousKeys()
    {
        return [];
    }
}

class FleetOpsPushAppsDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

/** Records the push it was asked to send and answers with a set outcome. */
class FleetOpsPushAppsChannelFake extends PushAppChannel
{
    public array $sent = [];

    public function __construct(public ?PushOutcome $outcome = null)
    {
    }

    public function send($notifiable, Notification $notification): array
    {
        $devices      = PushAppChannel::devicesFor($notifiable);
        $this->sent[] = [$devices->pluck('token')->all(), $notification->toPushApp($notifiable)?->title, $devices->first()?->environment];

        return $this->outcome ? [$devices->first()->token => $this->outcome] : [];
    }
}

function fleetopsPushAppsDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table push_apps (id integer primary key autoincrement, uuid varchar(64) null, public_id varchar(64) null, company_uuid varchar(64) null, created_by_uuid varchar(64) null, name varchar(255) null, key varchar(255) null, providers text null, status varchar(32) null, owner_uuid varchar(64) null, owner_type varchar(255) null, meta text null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table user_devices (id integer primary key autoincrement, uuid varchar(64) null, public_id varchar(64) null, user_uuid varchar(64) null, company_uuid varchar(64) null, push_app_uuid varchar(64) null, platform varchar(32) null, push_provider varchar(32) null, app_identifier varchar(64) null, environment varchar(32) null, token varchar(255) null, status varchar(32) null, last_seen_at datetime null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');

    $resolver = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsPushAppsDatabaseProbe($connection));
    EloquentModel::encryptUsing(new FleetOpsPushAppsEncrypter());
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    PushApp::clearBootedModels();
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    if (!app()->bound('cache') || !method_exists(app('cache'), 'tags')) {
        app()->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    }
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    config()->set('api.cache.enabled', false);
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    return $connection;
}

function fleetopsPushAppsApn(array $overrides = []): array
{
    return array_replace(['key_id' => 'KEY123ABCD', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.bayline.rides', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----"], $overrides);
}

function fleetopsPushAppsAccount(): string
{
    return json_encode(['project_id' => 'bayline-rides', 'client_email' => 'push@bayline-rides.iam.gserviceaccount.com', 'private_key' => 'secret']);
}

function fleetopsPushAppsJson($response): array
{
    return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
}

/** The error message of a refused request, whichever shape the response macro gives it. */
function fleetopsPushAppsError($response): ?string
{
    $body = fleetopsPushAppsJson($response)['body'];

    return $body['errors'][0] ?? $body['error'] ?? null;
}

test('drivers and customers send push-app devices to their app and the rest to the install-wide channels', function () {
    $devices = collect([
        new UserDevice(['platform' => 'ios', 'token' => 'navigator-ios']),
        new UserDevice(['platform' => 'android', 'token' => 'navigator-android']),
        new UserDevice(['platform' => 'ios', 'token' => 'rides-ios', 'push_app_uuid' => 'app-uuid']),
        new UserDevice(['platform' => 'android', 'token' => 'rides-android', 'push_app_uuid' => 'app-uuid']),
    ]);

    foreach ([new Driver(), new Contact()] as $notifiable) {
        $notifiable->setRelation('devices', $devices);

        expect($notifiable->routeNotificationForApn())->toBe(['navigator-ios'])
            ->and($notifiable->routeNotificationForFcm())->toBe(['navigator-android'])
            ->and($notifiable->routeNotificationForPushApp()->pluck('token')->all())->toBe(['rides-ios', 'rides-android']);
    }

    expect((new PushAppTest('Test push', 'It works.'))->toPushApp(null)->title)->toBe('Test push');
});

test('a registering device is linked to the push app its key names', function () {
    fleetopsPushAppsDatabase();
    $app = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'providers' => ['apn' => fleetopsPushAppsApn()]]);

    expect(PushAppDevices::resolve(Request::create('/', 'POST'), 'company-uuid'))->toBeNull()
        ->and(PushAppDevices::resolve(Request::create('/', 'POST', ['push_app' => 'push_app_unknown']), 'company-uuid'))->toBeFalse()
        ->and(PushAppDevices::resolve(Request::create('/', 'POST', ['push_app' => $app->key]), 'other-company'))->toBeFalse()
        ->and(PushAppDevices::resolve(Request::create('/', 'POST', ['push_app' => $app->key]), 'company-uuid')->uuid)->toBe($app->uuid);

    $device = UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'rides-ios', 'status' => 'active']);
    $other  = new stdClass();

    expect(PushAppDevices::link($device, null, Request::create('/')))->toBe($device)
        ->and(PushAppDevices::link($other, $app, Request::create('/')))->toBe($other)
        ->and($device->push_app_uuid)->toBeNull();

    PushAppDevices::link($device, $app, Request::create('/', 'POST', ['environment' => 'Sandbox', 'push_provider' => 'APNs']));
    $saved = UserDevice::where('uuid', $device->uuid)->first();
    expect($saved->push_app_uuid)->toBe($app->uuid)
        ->and($saved->company_uuid)->toBe('company-uuid')
        ->and($saved->app_identifier)->toBe($app->uuid)
        ->and($saved->environment)->toBe('sandbox')
        ->and($saved->push_provider)->toBe('apn');

    // Registering again with an unknown environment or provider changes nothing, so nothing is saved.
    PushAppDevices::link($saved, $app, Request::create('/', 'POST', ['environment' => 'staging', 'push_provider' => 'pigeon']));
    expect($saved->isDirty())->toBeFalse()->and($saved->environment)->toBe('sandbox')->and($saved->push_provider)->toBe('apn');

    // An iOS app that gets its token from Firebase says so.
    PushAppDevices::link($saved, $app, Request::create('/', 'POST', ['push_provider' => 'firebase']));
    expect(UserDevice::where('uuid', $device->uuid)->first()->push_provider)->toBe('fcm');
});

test('settings lists, adds and edits push apps without ever returning their keys', function () {
    fleetopsPushAppsDatabase();
    $controller = new PushAppController();

    expect(fleetopsPushAppsJson($controller->index()))->toBe(['status' => 200, 'body' => ['push_apps' => []]]);

    $created = fleetopsPushAppsJson($controller->store(Request::create('/', 'POST', [
        'name' => ' Bayline Rides ',
        'apn'  => fleetopsPushAppsApn(['environment' => 'production']),
        'fcm'  => ['credentials' => fleetopsPushAppsAccount(), 'android_channel_id' => 'trips'],
    ])));
    $app = $created['body']['push_app'];

    expect($created['status'])->toBe(200)
        ->and($app['name'])->toBe('Bayline Rides')
        ->and($app['key'])->toStartWith('push_app_')
        ->and($app['status'])->toBe('active')
        ->and($app['providers']['apn'])->toBe(['key_id' => 'KEY123ABCD', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.bayline.rides', 'environment' => 'production', 'has_key' => true])
        ->and($app['providers']['fcm'])->toBe(['project_id' => 'bayline-rides', 'client_email' => 'push@bayline-rides.iam.gserviceaccount.com', 'android_channel_id' => 'trips', 'has_credentials' => true])
        ->and($app['devices'])->toBe(0)
        ->and(json_encode($created))->not->toContain('PRIVATE KEY')->not->toContain('secret')
        ->and(PushApp::where('public_id', $app['id'])->first()->created_by_uuid)->toBe('user-uuid');

    // Editing without the keys keeps them; the Android channel and status change.
    $updated = fleetopsPushAppsJson($controller->update($app['id'], Request::create('/', 'PUT', [
        'name'   => 'Bayline',
        'status' => 'inactive',
        'apn'    => fleetopsPushAppsApn(['key_id' => 'KEY999ABCD', 'private_key_content' => '']),
        'fcm'    => ['credentials' => '', 'android_channel_id' => ''],
    ])))['body']['push_app'];
    $saved = PushApp::where('public_id', $app['id'])->first();

    expect($updated['name'])->toBe('Bayline')
        ->and($updated['status'])->toBe('inactive')
        ->and($updated['providers']['apn']['key_id'])->toBe('KEY999ABCD')
        ->and($updated['key'])->toBe($app['key'])
        ->and($saved->provider('apn')['private_key_content'])->toContain('BEGIN PRIVATE KEY')
        ->and($saved->provider('fcm')['credentials'])->toBe(fleetopsPushAppsAccount())
        ->and($updated['providers']['fcm']['android_channel_id'])->toBeNull();

    // Clearing iOS turns it off.
    $cleared = fleetopsPushAppsJson($controller->update($app['id'], Request::create('/', 'PUT', ['apn' => ['key_id' => '', 'team_id' => '']])))['body']['push_app'];
    expect($cleared['providers'])->not->toHaveKey('apn')->and($cleared['providers'])->toHaveKey('fcm')->and($cleared['name'])->toBe('Bayline');

    UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'android', 'token' => 'rides-android', 'push_app_uuid' => $saved->uuid]);
    PushApp::create(['name' => 'Other organization', 'company_uuid' => 'other-company']);
    $listed = fleetopsPushAppsJson($controller->index())['body']['push_apps'];

    expect(array_column($listed, 'name'))->toBe(['Bayline'])->and($listed[0]['devices'])->toBe(1);
});

test('settings explains what is wrong with a push app it can not save', function () {
    fleetopsPushAppsDatabase();
    $controller = new PushAppController();
    $error      = fn (array $input) => fleetopsPushAppsError($controller->store(Request::create('/', 'POST', array_replace(['name' => 'Bayline Rides'], $input))));

    // Each provider's own messages; core-api tests every one of them.
    expect($error(['name' => '  ']))->toContain('Give the push app a name')
        ->and($error(['status' => 'paused']))->toBe('Choose whether the push app is on or off.')
        ->and($error(['apn' => ['team_id' => 'TEAM123456']]))->toContain('Enter the Key ID of your Apple push key')
        ->and($error(['apn' => fleetopsPushAppsApn(['app_bundle_id' => 'Bayline Rides'])]))->toContain('should look like com.acme.driver')
        ->and($error(['fcm' => ['credentials' => json_encode(['project_info' => [], 'client' => []])]]))->toContain('looks like google-services.json');

    $empty = fleetopsPushAppsJson($controller->store(Request::create('/', 'POST', ['name' => 'Web only', 'apn' => [], 'fcm' => []])))['body']['push_app'];
    expect($empty['providers'])->toBe([]);

    $app = PushApp::where('public_id', $empty['id'])->first();
    expect(fleetopsPushAppsError($controller->update($empty['id'], Request::create('/', 'PUT', ['name' => '']))))->toContain('Give the push app a name')
        ->and($app->name)->toBe('Web only');
});

test('settings removes push apps and refuses ones of other organizations', function () {
    fleetopsPushAppsDatabase();
    $controller = new PushAppController();
    $app        = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid']);
    $other      = PushApp::create(['name' => 'Harbour Cabs', 'company_uuid' => 'other-company']);

    expect(fleetopsPushAppsJson($controller->update($other->public_id, Request::create('/', 'PUT', ['name' => 'Mine'])))['status'])->toBe(404)
        ->and(fleetopsPushAppsJson($controller->destroy($other->public_id))['status'])->toBe(404)
        ->and(fleetopsPushAppsJson($controller->sendTest($other->public_id, Request::create('/', 'POST')))['status'])->toBe(404)
        ->and(fleetopsPushAppsJson($controller->destroy($app->uuid)))->toBe(['status' => 200, 'body' => ['status' => 'ok']])
        ->and(PushApp::where('uuid', $app->uuid)->first())->toBeNull();

    $noOrganization = new class extends PushAppController {
        protected function companyUuid(): ?string
        {
            return null;
        }
    };
    expect(fleetopsPushAppsJson($noOrganization->index())['status'])->toBe(404)
        ->and(fleetopsPushAppsJson($noOrganization->store(Request::create('/', 'POST', ['name' => 'X'])))['status'])->toBe(404)
        ->and(fleetopsPushAppsJson($noOrganization->destroy($other->public_id))['status'])->toBe(404);
});

test('settings sends a test push to one device with the app credentials', function () {
    fleetopsPushAppsDatabase();
    $controller = new PushAppController();
    $app        = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'providers' => ['apn' => fleetopsPushAppsApn()]]);
    $send       = fn (array $input) => fleetopsPushAppsJson($controller->sendTest($app->public_id, Request::create('/', 'POST', $input)));
    $refusal    = fn (array $input) => fleetopsPushAppsError($controller->sendTest($app->public_id, Request::create('/', 'POST', $input)));

    expect($refusal(['push_provider' => 'apn']))->toBe('Paste a device token and choose which service issued it.')
        ->and($refusal(['token' => 'abc', 'platform' => 'android']))->toContain('no Firebase service account yet')
        ->and($refusal(['token' => 'instance:APA91token']))->toContain('no Firebase service account yet');

    $app->update(['providers' => ['fcm' => ['credentials' => fleetopsPushAppsAccount()]]]);
    expect($refusal(['token' => 'abc', 'push_provider' => 'apns']))->toContain('no Apple settings yet')
        ->and($refusal(['token' => 'abc', 'push_provider' => 'pigeon', 'platform' => 'web']))->toContain('This is an Apple device token');

    $channel = new FleetOpsPushAppsChannelFake(PushOutcome::sent());
    app()->instance(PushAppChannel::class, $channel);
    expect($send(['token' => 'abc', 'push_provider' => 'fcm', 'title' => 'Hello']))->toBe(['status' => 200, 'body' => ['status' => 'sent', 'reason' => null, 'sent' => true]])
        ->and($channel->sent[0])->toBe([['abc'], 'Hello', null]);

    // An iOS app's Firebase token is sent through Firebase without choosing it.
    app()->instance(PushAppChannel::class, new FleetOpsPushAppsChannelFake(PushOutcome::wrongApp('SenderId mismatch')));
    expect($send(['token' => 'instance:APA91token', 'platform' => 'ios'])['body'])->toBe(['status' => 'wrong_app', 'reason' => 'SenderId mismatch', 'sent' => false]);

    app()->instance(PushAppChannel::class, new FleetOpsPushAppsChannelFake());
    expect($send(['token' => 'abc', 'platform' => 'android'])['body'])->toMatchArray(['status' => 'error', 'sent' => false]);
});
