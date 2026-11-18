<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Notifications\PushAppTest;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Push\PushOutcome;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Settings → Push apps: the organization's own apps and the APNs and FCM credentials each
 * one's pushes are sent with. Keys are written here but never read back.
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

        $token    = trim((string) $request->input('token'));
        $platform = UserDevice::normalizePlatform($request->input('platform'));
        if ($token === '' || !$platform) {
            return response()->error('Enter a device token and choose iOS or Android.', 422);
        }
        if (!$app->credentials($platform === 'ios' ? 'apn' : 'fcm')) {
            return response()->error($platform === 'ios' ? 'Add the app\'s APNs key first.' : 'Add the app\'s Firebase service account first.', 422);
        }

        $device = new UserDevice(['token' => $token, 'platform' => $platform, 'push_app_uuid' => $app->uuid, 'status' => 'active', 'environment' => $request->input('environment')]);
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
            return 'Give the push app a name.';
        }

        $attributes = ['name' => $name];
        if ($request->has('status')) {
            if (!in_array($request->input('status'), ['active', 'inactive'], true)) {
                return 'Choose whether the push app is on or off.';
            }
            $attributes['status'] = $request->input('status');
        }

        if ($request->has('apn')) {
            $apn = $this->apn((array) $request->input('apn'), $app);
            if (is_string($apn)) {
                return $apn;
            }
            $attributes['apn'] = $apn;
        }

        if ($request->has('fcm')) {
            $fcm = $this->fcm((array) $request->input('fcm'), $app);
            if (is_string($fcm)) {
                return $fcm;
            }
            $attributes['fcm'] = $fcm;
        }

        return $attributes;
    }

    /**
     * APNs settings: all of key ID, team ID, bundle ID and a .p8 key, or none to turn iOS off.
     * A key left empty when editing keeps the saved one.
     */
    protected function apn(array $input, ?PushApp $app): array|string|null
    {
        $apn = array_map(fn ($value) => is_string($value) ? trim($value) : $value, array_intersect_key($input, array_flip(['key_id', 'team_id', 'app_bundle_id', 'private_key_content', 'environment'])));
        if (!array_filter($apn)) {
            return null;
        }

        foreach (['key_id' => 'the key ID', 'team_id' => 'the team ID', 'app_bundle_id' => 'the bundle ID'] as $field => $label) {
            if (empty($apn[$field])) {
                return 'Enter ' . $label . ' for iOS, or clear the iOS settings.';
            }
        }
        if (empty($apn['private_key_content']) && empty($app?->apn['private_key_content'])) {
            return 'Add the .p8 key for iOS.';
        }
        if (!empty($apn['private_key_content']) && !str_contains($apn['private_key_content'], 'PRIVATE KEY')) {
            return 'The iOS key should be the contents of the .p8 file, starting with "-----BEGIN PRIVATE KEY-----".';
        }
        if (!empty($apn['environment']) && !in_array($apn['environment'], ['production', 'sandbox'], true)) {
            return 'Choose production or sandbox for iOS, or leave it to try both.';
        }

        return $apn;
    }

    /**
     * FCM settings: the Firebase service account JSON, or none to turn Android off. Left empty
     * when editing, the saved one is kept.
     */
    protected function fcm(array $input, ?PushApp $app): array|string|null
    {
        $credentials = $input['credentials'] ?? null;
        $channel     = trim((string) ($input['android_channel_id'] ?? ''));
        if (empty($credentials)) {
            if (empty($app?->fcm['credentials'])) {
                return $channel === '' ? null : 'Add the Firebase service account for Android.';
            }

            return array_filter(['credentials' => null, 'android_channel_id' => $channel ?: null]);
        }

        $account = is_string($credentials) ? json_decode(trim($credentials), true) : $credentials;
        if (!is_array($account) || empty($account['project_id']) || empty($account['client_email']) || empty($account['private_key'])) {
            return 'The Firebase service account should be the JSON file from Firebase, with project_id, client_email and private_key.';
        }

        return array_filter(['credentials' => json_encode($account), 'android_channel_id' => $channel ?: null]);
    }

    protected function present(PushApp $app): array
    {
        return [
            'id'           => $app->public_id,
            'name'         => $app->name,
            'key'          => $app->key,
            'status'       => $app->status,
            'apn'          => $app->apn_summary,
            'fcm'          => $app->fcm_summary,
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
