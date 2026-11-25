<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Mail\NavigatorAppLinkMail;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Support\Authorization;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Services\SmsService;
use Fleetbase\Support\Barcode;
use Fleetbase\Support\PlatformApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;

class NavigatorController extends Controller
{
    use AuthorizesMethods;
    /**
     * How long a Navigator link issued from the console stays valid.
     */
    public const LINK_TTL_MINUTES = 30;

    /**
     * How long a link sent to a driver stays valid: they may not open it the same day.
     */
    public const SHARED_LINK_TTL_MINUTES = 60 * 24 * 7;

    /**
     * The most drivers one share request may message.
     */
    public const SHARE_LIMIT = 200;

    public function __construct()
    {
        // System administrators always; an organization's own staff once the link
        // carries the platform token rather than an organization's API key.
        $this->middleware(function ($request, $next) {
            if ($this->linkUsesPlatformToken()) {
                if (!Authorization::canAny('view navigator-settings', 'update navigator-settings')) {
                    Authorization::authorizeAdmin();
                }
            } else {
                Authorization::authorizeAdmin();
            }

            return $next($request);
        })->only(['getLinkAppUrl', 'shareLink']);
    }

    /**
     * Redirects to the Fleetbase Navigator app using a deep link.
     * Automatically detects the platform (iOS or Android) and uses the correct URI scheme.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function linkApp(Request $request)
    {
        // This route is opened from a phone without a console session, so it is
        // authorised by the short-lived signature issued by getLinkAppUrl().
        if (!$this->hasValidLinkSignature($request)) {
            return response()->error('This Navigator link is invalid or has expired. Generate a new one from the console.', 403);
        }

        $deepLinkParams = $this->deepLinkParams();
        if ($deepLinkParams === null) {
            return response()->error('Organization for linking not found.');
        }

        $appIdentifier = $this->navigatorAppIdentifier();
        $scheme        = $this->navigatorLinkScheme();

        $userAgent = $request->header('User-Agent');
        if (stripos($userAgent, 'android') !== false) {
            // Android: Use intent:// scheme
            $intentUrl = "intent://configure?$deepLinkParams#Intent;scheme=$scheme;package=" . $appIdentifier . ';end';

            return $this->redirectAway($intentUrl);
        }

        // Default to iOS (or fallback): the app's own scheme
        $iosUrl = "$scheme://configure?$deepLinkParams";

        return $this->redirectAway($iosUrl);
    }

    /**
     * Everything the console needs to link a Navigator app to this instance:
     *
     *  - `linkUrl`: a signed web link that opens the app when tapped on a phone. It
     *    expires, so it is the one to send in a message.
     *  - `deepLink`: the app link itself, with the server address, the access key and
     *    the live-update settings.
     *  - `qrCode`: `deepLink` as a QR code (an SVG data URI). The app's own scanner and
     *    the phone's camera both read it.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLinkAppUrl()
    {
        $deepLinkParams = $this->deepLinkParams();
        if ($deepLinkParams === null) {
            return response()->error('Organization for linking not found.');
        }

        $deepLink = $this->navigatorLinkScheme() . '://configure?' . $deepLinkParams;
        $expires  = now()->addMinutes(static::LINK_TTL_MINUTES);

        return response()->json([
            'linkUrl'            => static::signedLinkUrl($expires->getTimestamp()),
            'expiresAt'          => $expires->toISOString(),
            'deepLink'           => $deepLink,
            'qrCode'             => Barcode::qrCodeDataUri($deepLink, 'M'),
            'qrCodeSvg'          => Barcode::qrCodeSvg($deepLink, 'M'),
            'host'               => $this->secureRootUrl(),
            'socketHost'         => $this->socketClusterHost(),
            'socketPort'         => $this->socketClusterPort(),
            'socketSecure'       => $this->socketClusterSecure(),
            // False means the link still carries an organization's API key: rotating the
            // platform token once (Admin > Platform API token) switches it over.
            'usesPlatformToken'  => $this->linkUsesPlatformToken(),
            'platformConfigured' => PlatformApi::isConfigured(),
        ]);
    }

    /**
     * Sends the Navigator link to drivers of the current organization, by email where
     * the driver has one and by text message otherwise.
     *
     * Body: `drivers` (ids of the drivers to send to) or `all: true`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function shareLink(Request $request)
    {
        $ids = array_values(array_filter((array) $request->input('drivers', []), 'is_string'));
        $all = $request->boolean('all');

        if (!$all && empty($ids)) {
            return response()->error('Choose at least one driver to send the link to.', 422);
        }

        $query = Driver::where('company_uuid', session('company'))->with(['user', 'company']);
        if (!$all) {
            $query->where(function ($q) use ($ids) {
                $q->whereIn('public_id', $ids)->orWhereIn('uuid', $ids);
            });
        }
        $drivers = $query->limit(static::SHARE_LIMIT)->get();

        if ($drivers->isEmpty()) {
            return response()->error('None of those drivers were found.', 404);
        }

        $expires = now()->addMinutes(static::SHARED_LINK_TTL_MINUTES);
        $linkUrl = static::signedLinkUrl($expires->getTimestamp());
        $sent    = [];
        $failed  = [];

        foreach ($drivers as $driver) {
            try {
                $sent[] = ['driver' => $driver->public_id, 'name' => $driver->name, 'via' => $this->deliverLink($driver, $linkUrl)];
            } catch (\Throwable $e) {
                $failed[] = ['driver' => $driver->public_id, 'name' => $driver->name, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'status'    => empty($sent) ? 'failed' : 'ok',
            'sent'      => $sent,
            'failed'    => $failed,
            'expiresAt' => $expires->toISOString(),
        ]);
    }

    /**
     * @return string the channel used: `email` or `sms`
     */
    protected function deliverLink(Driver $driver, string $linkUrl): string
    {
        $user        = $driver->user;
        $companyName = $driver->company?->name ?? config('app.name');
        $email       = $user?->email ?: $driver->email;
        $phone       = $user?->phone ?: $driver->phone;

        if ($email) {
            Mail::to($email)->send(new NavigatorAppLinkMail($driver, $linkUrl, $companyName));

            return 'email';
        }

        if ($phone) {
            $result = app(SmsService::class)->send($phone, "{$companyName}: open this on your phone to connect the driver app. {$linkUrl}", [
                'purpose'      => 'navigator_link',
                'company_uuid' => $driver->company_uuid,
            ]);

            if (is_array($result) && array_key_exists('success', $result) && !$result['success']) {
                throw new \Exception($result['error'] ?? $result['message'] ?? 'The SMS provider refused it.');
            }

            return 'sms';
        }

        throw new \Exception('No email or phone number on file.');
    }

    /**
     * Retrieves the current organization based on the bearer token (API key or secret).
     *
     * Determines the correct database connection based on the API key format,
     * retrieves the associated API credential, and returns the organization resource.
     *
     * @return \Illuminate\Http\JsonResponse|\App\Http\Resources\Organization
     */
    public function getCurrentOrganization(Request $request)
    {
        $token       = $request->bearerToken();
        $isSecretKey = Str::startsWith($token, '$');

        // Depending on API key format set the connection to find credential on
        $connection = Str::startsWith($token, 'flb_test_') ? 'sandbox' : 'mysql';

        // Find the API Credential record
        $apiCredential = $this->findApiCredentialForToken($token, $connection, $isSecretKey);

        // Handle no api credential found
        if (!$apiCredential) {
            return response()->error('No API key found to fetch company details with.');
        }

        // Get the organization owning the API key
        $organization = $this->findOrganization($apiCredential->company_uuid);

        return new Organization($organization);
    }

    /**
     * Retrieves the driver onboarding settings from the system configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings()
    {
        $onBoardSettings = $this->driverOnboardSettings();

        return response()->json($onBoardSettings);
    }

    protected function findAdminUser(): ?User
    {
        return User::where('type', 'admin')->first();
    }

    protected function firstOrCreateNavigatorCredential(User $adminUser): ApiCredential
    {
        return ApiCredential::firstOrCreate(
            [
                'user_uuid'    => $adminUser->uuid,
                'company_uuid' => $adminUser->company_uuid,
                'name'         => 'NavigationAppLinker',
            ],
            [
                'user_uuid'    => $adminUser->uuid,
                'company_uuid' => $adminUser->company->uuid,
                'name'         => 'NavigationAppLinker',
            ]
        );
    }

    /**
     * The key an app presents until a driver signs in: the instance's platform token
     * when it can be read, which opens only the sign-in routes. Before the token has
     * been rotated under a core that keeps it readable, the link falls back to the
     * linking organization's API key, as it always did.
     */
    protected function linkCredential(): ?string
    {
        $platformToken = $this->platformToken();
        if ($platformToken !== null) {
            return $platformToken;
        }

        $adminUser = $this->findAdminUser();
        if (!$adminUser || !$adminUser->company) {
            return null;
        }

        return $this->firstOrCreateNavigatorCredential($adminUser)->key;
    }

    protected function platformToken(): ?string
    {
        return method_exists(PlatformApi::class, 'token') ? PlatformApi::token() : null;
    }

    protected function linkUsesPlatformToken(): bool
    {
        return $this->platformToken() !== null;
    }

    /**
     * The query string of the app link, or null when there is no key to put in it.
     */
    protected function deepLinkParams(): ?string
    {
        $key = $this->linkCredential();
        if ($key === null) {
            return null;
        }

        return http_build_query([
            'key'                  => $key,
            'host'                 => $this->secureRootUrl(),
            'socketcluster_host'   => $this->socketClusterHost(),
            'socketcluster_port'   => $this->socketClusterPort(),
            'socketcluster_secure' => $this->socketClusterSecure() ? 'true' : 'false',
        ]);
    }

    /**
     * The app link with an organization's join code on it: it connects the app to this
     * instance and starts sign-up for that organization.
     */
    public function joinLink(string $joinCode): ?string
    {
        $params = $this->deepLinkParams();
        if ($params === null) {
            return null;
        }

        return $this->navigatorLinkScheme() . '://configure?' . $params . '&' . http_build_query(['join' => $joinCode]);
    }

    protected function navigatorLinkScheme(): string
    {
        return config('fleetops.navigator.link_scheme', 'flbnavigator');
    }

    protected function secureRootUrl(): string
    {
        return url()->secure('/');
    }

    protected function navigatorAppIdentifier(): string
    {
        return config('fleetops.navigator.app_identifier', 'io.fleetbase.navigator');
    }

    protected function socketClusterHost(): string
    {
        return env('SOCKETCLUSTER_HOST', 'socket');
    }

    protected function socketClusterPort(): int|string
    {
        return env('SOCKETCLUSTER_PORT', 8000);
    }

    protected function socketClusterSecure(): bool
    {
        return Utils::castBoolean(env('SOCKETCLUSTER_SECURE', false));
    }

    protected function redirectAway(string $url)
    {
        return Redirect::away($url);
    }

    protected function findApiCredentialForToken(?string $token, string $connection, bool $isSecretKey): ?ApiCredential
    {
        return ApiCredential::on($connection)
            ->where(function ($query) use ($isSecretKey, $token) {
                if ($isSecretKey) {
                    $query->where('secret', $token);
                } else {
                    $query->where('key', $token);
                }
            })
            ->with(['company.owner'])
            ->withoutGlobalScopes()
            ->first();
    }

    protected function findOrganization(string $companyUuid): ?Company
    {
        return Company::where('uuid', $companyUuid)->first();
    }

    protected function driverOnboardSettings(): mixed
    {
        return Setting::where('key', 'fleet-ops.driver-onboard')->value('value');
    }

    /**
     * HMAC signature for a Navigator link expiring at the given unix timestamp.
     */
    public static function linkSignature(int $expires): string
    {
        return hash_hmac('sha256', 'fleet-ops.navigator.link-app|' . $expires, (string) config('app.key'));
    }

    /**
     * The signed web link that redirects a phone into the app, valid until `$expires`.
     */
    public static function signedLinkUrl(int $expires): string
    {
        return url('int/v1/fleet-ops/navigator/link-app') . '?' . http_build_query([
            'expires'   => $expires,
            'signature' => static::linkSignature($expires),
        ]);
    }

    protected function hasValidLinkSignature(Request $request): bool
    {
        $expires   = (int) $request->query('expires');
        $signature = (string) $request->query('signature');

        return $signature !== '' && $expires >= time() && hash_equals(static::linkSignature($expires), $signature);
    }
}
