<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Notifications\PushAppTest;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Push\PushConfigurationException;
use Fleetbase\Push\PushOutcome;
use Fleetbase\Push\PushProviders;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Settings → Push apps: the organization's own apps and each one's settings for the push
 * providers it sends through (Apple's `apn` and Firebase's `fcm`, plus any registered with
 * PushProviders). Each provider's settings are sent under its key and checked by the
 * provider. Secrets are written here but never read back.
 */
class PushAppController extends Controller
{
    use AuthorizesMethods;

    public function __construct()
    {
        $this->authorizeMethods([
            'index'    => 'view notification-settings',
            'store'    => 'update notification-settings',
            'update'   => 'update notification-settings',
            'destroy'  => 'update notification-settings',
            'sendTest' => 'update notification-settings',
        ]);
    }

    public function index()
    {
        $companyUuid = $this->companyUuid();
        if (!$companyUuid) {
            return response()->error('No organization found.', 404);
        }

        $apps = PushApp::where('company_uuid', $companyUuid)->withCount('devices')->orderBy('name')->get();

        return response()->json(['push_apps' => $apps->map(fn (PushApp $app) => $this->present($app))->values()]);
    }

    public function store(Request $request)
    {
        $companyUuid = $this->companyUuid();
        if (!$companyUuid) {
            return response()->error('No organization found.', 404);
        }

        $attributes = $this->validated($request, null);
        if (is_string($attributes)) {
            return response()->error($attributes, 422);
        }

        $app = PushApp::create(array_replace($attributes, ['company_uuid' => $companyUuid, 'created_by_uuid' => session('user')]));

        return response()->json(['push_app' => $this->present($app)]);
    }

    public function update(string $id, Request $request)
    {
        $app = $this->find($id);
        if (!$app) {
            return response()->error('Push app not found.', 404);
        }

        $attributes = $this->validated($request, $app);
        if (is_string($attributes)) {
            return response()->error($attributes, 422);
        }

        $app->update($attributes);

        return response()->json(['push_app' => $this->present($app->refresh())]);
    }

    public function destroy(string $id)
    {
        $app = $this->find($id);
        if (!$app) {
            return response()->error('Push app not found.', 404);
        }

        $app->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Sends a test push to one device token with the app's credentials.
     */
    public function sendTest(string $id, Request $request)
    {
        $app = $this->find($id);
        if (!$app) {
            return response()->error('Push app not found.', 404);
        }

        $token  = trim((string) $request->input('token'));
        $device = new UserDevice([
            'token'         => $token,
            'platform'      => UserDevice::normalizePlatform($request->input('platform')) ?? 'ios',
            'push_provider' => PushProviders::normalize($request->input('push_provider')),
            'push_app_uuid' => $app->uuid,
            'status'        => 'active',
            'environment'   => $request->input('environment') ?: null,
        ]);
        $provider = app(PushProviders::class)->forDevice($device);
        if ($token === '' || !$provider) {
            return response()->error('Paste a device token and choose which service issued it.', 422);
        }
        if (!$app->credentials($provider)) {
            return response()->error(match ($provider) {
                'apn'   => 'This is an Apple device token, and the app has no Apple settings yet. Add them, or choose Firebase if the app gets its tokens from Firebase.',
                'fcm'   => 'This is a Firebase token, and the app has no Firebase service account yet. Add one first.',
                default => 'The app isn\'t set up for the service that issued this token.',
            }, 422);
        }

        $target = new class([$device]) {
            public function __construct(public array $devices)
            {
            }

            public function routeNotificationForPushApp(): array
            {
                return $this->devices;
            }
        };

        $outcomes = app(PushAppChannel::class)->send($target, new PushAppTest((string) ($request->input('title') ?: 'Test push'), (string) ($request->input('message') ?: 'Push notifications from ' . $app->name . ' work.')));
        $outcome  = $outcomes[$token] ?? PushOutcome::error('The push could not be sent. Check the app\'s credentials.');

        return response()->json(['status' => $outcome->status, 'reason' => $outcome->reason, 'sent' => $outcome->is(PushOutcome::SENT)]);
    }

    /**
     * The attributes to save, or a message saying what's wrong.
     */
    protected function validated(Request $request, ?PushApp $app): array|string
    {
        $name = trim((string) $request->input('name', $app?->name));
        if ($name === '') {
            return 'Give the push app a name, for example the app\'s name on the App Store.';
        }

        $attributes = ['name' => $name];
        if ($request->has('status')) {
            if (!in_array($request->input('status'), ['active', 'inactive'], true)) {
                return 'Choose whether the push app is on or off.';
            }
            $attributes['status'] = $request->input('status');
        }

        $providers = app(PushProviders::class);
        $configs   = $app ? (array) $app->providers : [];
        foreach ($providers->keys() as $key) {
            if (!$request->has($key)) {
                continue;
            }
            try {
                $config = $providers->get($key)->configure((array) $request->input($key), $app?->provider($key));
            } catch (PushConfigurationException $e) {
                return $e->getMessage();
            }
            if ($config === null) {
                unset($configs[$key]);
            } else {
                $configs[$key] = $config;
            }
        }
        $attributes['providers'] = $configs;

        return $attributes;
    }

    protected function present(PushApp $app): array
    {
        return [
            'id'           => $app->public_id,
            'name'         => $app->name,
            'key'          => $app->key,
            'status'       => $app->status,
            'providers'    => (object) $app->summaries,
            'devices'      => (int) ($app->devices_count ?? 0),
            'created_at'   => $app->created_at,
        ];
    }

    protected function find(string $id): ?PushApp
    {
        $companyUuid = $this->companyUuid();

        return $companyUuid ? PushApp::where('company_uuid', $companyUuid)->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))->first() : null;
    }

    protected function companyUuid(): ?string
    {
        return session('company') ?: Auth::getCompany()?->uuid;
    }
}
