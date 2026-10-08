<?php

namespace Fleetbase\FleetOps\Http\Controllers\Public;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingChannel;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingAccess;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingRecipient;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingResolver;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingTarget;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingView;
use Fleetbase\FleetOps\Support\TrackingPublisher;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The public customer tracking page's API.
 *
 * A tracking number alone gives level 0. A device that entered a correct one-time code
 * gets level 1 for one customer within the order; a signed-in customer gets level 2;
 * staff of the owning company see the customer's view. Unknown, malformed, opted-out,
 * other-company and throttled lookups all get the same 404, so a caller can't tell
 * which one they hit.
 *
 * `?org={slug}` on any route scopes it to that company's own page.
 */
class TrackingController extends Controller
{
    public const NOT_FOUND = 'We can\'t show tracking for that number. Check it matches your email or parcel label and try again.';

    public function __construct(
        protected ?TrackingResolver $resolver = null,
        protected ?TrackingAccess $access = null,
        protected ?TrackingView $view = null,
    ) {
        $this->resolver ??= new TrackingResolver();
        $this->access ??= new TrackingAccess();
        $this->view ??= new TrackingView();
    }

    /**
     * The page's branding and what it offers, before any lookup.
     */
    public function config(Request $request): JsonResponse
    {
        $slug = $this->slug($request);
        if ($slug !== null) {
            $companyUuid = $this->resolver->companyForSlug($slug);
            if ($companyUuid === null) {
                return $this->json(['error' => 'page_not_found'], 404);
            }

            $config = $this->resolver->config($companyUuid);

            return $this->json([
                'company'       => $this->view->branding($config, $this->findCompany($companyUuid)),
                'org'           => $slug,
                'sign_in'       => $this->signInOffered($config),
                'session_hours' => $config['access']['session_hours'],
            ]);
        }

        $admin = $this->resolver->adminConfig();
        if (!$admin['generic_page']['enabled']) {
            return $this->json(['error' => 'page_not_found'], 404);
        }

        return $this->json([
            'company'       => $this->view->branding(['branding' => $admin['branding']], null, $this->instanceLogo()),
            'org'           => null,
            'sign_in'       => $this->customerPortalInstalled(),
            'session_hours' => 24,
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        if (!$this->access->allowLookup($request)) {
            return $this->notFound();
        }

        $target = $this->resolve($request, $number);

        return $target ? $this->json($this->payload($request, $target)) : $this->notFound();
    }

    public function sendCode(Request $request, string $number): JsonResponse
    {
        $target = $this->resolve($request, $number);
        if (!$target) {
            return $this->notFound();
        }

        $recipient = $this->recipientFor($target);
        $channel   = (string) $request->input('channel');
        $channels  = collect($recipient->channels($target->config, $this->smsAvailable()));
        $selected  = $channels->firstWhere('type', $channel);
        if (!$target->scope || !$selected) {
            return $this->json(['error' => 'channel_unavailable'], 422);
        }

        $claim = $this->access->claimSend($target->scope, $request);
        if ($claim['status'] !== TrackingAccess::SEND_SENT) {
            return $this->json(['error' => $claim['status'], 'retry_after' => $claim['retry_after']], $claim['status'] === TrackingAccess::SEND_PAUSED ? 423 : 429);
        }

        $code = $this->access->issueCode($target->scope, $recipient->customer, $channel);
        $this->deliver($channel, (string) $recipient->destination($channel), (string) $code->plainCode, $target);

        return $this->json([
            'channel'      => $channel,
            'masked'       => $selected['masked'],
            'expires_in'   => TrackingAccess::CODE_MINUTES * 60,
            'resend_after' => $claim['retry_after'],
        ], 202);
    }

    public function verifyCode(Request $request, string $number): JsonResponse
    {
        if (!$this->access->allowVerify($request)) {
            return $this->json(['error' => 'limited', 'retry_after' => TrackingAccess::LOOKUP_WINDOW], 429);
        }

        $target = $this->resolve($request, $number);
        if (!$target || !$target->scope) {
            return $this->notFound();
        }

        if ($paused = $this->access->pausedFor($target->scope)) {
            return $this->json(['error' => 'paused', 'retry_after' => $paused], 423);
        }

        $result = $this->access->checkCode($target->scope, preg_replace('/\D/', '', (string) $request->input('code')));

        return match ($result['status']) {
            VerificationCode::CHECK_VALID   => $this->verified($request, $target, $result['channel'] ?? null),
            VerificationCode::CHECK_LOCKED  => $this->json(['error' => 'paused', 'retry_after' => TrackingAccess::PAUSE_SECONDS], 423),
            VerificationCode::CHECK_EXPIRED => $this->json(['error' => 'expired'], 410),
            default                         => $this->json(['error' => 'invalid', 'attempts_left' => $result['attempts_left']], 422),
        };
    }

    /**
     * Sign this device out: of one delivery with `?number=`, otherwise of every one.
     */
    public function signOut(Request $request): JsonResponse
    {
        $number = $request->input('number');
        $target = $number ? $this->resolve($request, $number) : null;
        $cookie = $this->access->revoke($request, $target?->scope);

        return $this->json(['status' => 'signed_out'], 200, $cookie);
    }

    public function updateInstructions(Request $request, string $number): JsonResponse
    {
        $target = $this->resolve($request, $number);
        $viewer = $target ? $this->viewerFor($request, $target) : null;
        if (!$target || !$viewer || !$target->scope) {
            return $this->notFound();
        }

        if (!data_get($target->config, 'visibility.instructions_edit', false)) {
            return $this->json(['error' => 'not_allowed'], 403);
        }

        $text = trim(mb_substr((string) $request->input('text', ''), 0, 500));
        $this->saveInstructions($target->order, $target->scope, $text);

        return $this->json($this->payload($request, $target));
    }

    public function proof(Request $request, string $number, string $id): JsonResponse
    {
        $target = $this->resolve($request, $number);
        if (!$target || !$this->viewerFor($request, $target)) {
            return $this->notFound();
        }

        $proof = $this->view->proofFor($target, $id);
        if (!$proof) {
            return $this->json(['error' => 'not_found'], 404);
        }

        $url = data_get($proof, 'file.url') ?? (is_string($proof->raw_data) && $proof->raw_data !== '' ? $this->dataUrl($proof->raw_data) : null);

        return $url ? $this->json(['url' => $url, 'type' => TrackingView::proofType($proof)]) : $this->json(['error' => 'not_found'], 404);
    }

    public function report(Request $request, string $number): JsonResponse
    {
        $target = $this->resolve($request, $number);
        if (!$target || !$this->viewerFor($request, $target)) {
            return $this->notFound();
        }

        $branding = $this->brandingFor($target);
        $to       = $branding['support_email'];
        $message  = trim(mb_substr((string) $request->input('message', ''), 0, 1000));
        if (!data_get($target->config, 'visibility.report_problem', true) || !$to || $message === '') {
            return $this->json(['error' => 'not_allowed'], 422);
        }

        if (!$this->access->allowReport($request, (string) $target->order->uuid)) {
            return $this->json(['error' => 'limited'], 429);
        }

        $this->sendReport($to, $target, $message);

        return $this->json(['status' => 'sent'], 202);
    }

    public function socketToken(Request $request, string $number): JsonResponse
    {
        $target = $this->resolve($request, $number);
        if (!$target || !$target->scope || !$this->viewerFor($request, $target) || !$this->socketsEnabled()) {
            return $this->notFound();
        }

        return $this->json($this->socketTokenFor($target->scope));
    }

    /**
     * A signed-in customer's orders, newest first.
     */
    public function accountOrders(Request $request): JsonResponse
    {
        $contacts = $this->contactsFor($this->requestUser($request));
        if ($contacts === []) {
            return $this->json(['error' => 'unauthenticated'], 401);
        }

        return $this->json(['orders' => $this->ordersForContacts($contacts)]);
    }

    protected function payload(Request $request, TrackingTarget $target): array
    {
        $branding = $this->brandingFor($target);
        $viewer   = $this->viewerFor($request, $target);

        if ($viewer) {
            $expiresAt = $viewer === TrackingView::VIEWER_VERIFIED ? $this->access->grantExpiresAt($request, $target->scope) : null;

            return $this->view->fullView($target, $branding, $viewer, $expiresAt);
        }

        $channels = $target->scope ? $this->recipientFor($target)->channels($target->config, $this->smsAvailable()) : [];

        return $this->view->publicView($target, $branding, $channels, $this->signInOffered($target->config));
    }

    protected function verified(Request $request, TrackingTarget $target, ?string $channel): JsonResponse
    {
        $hours  = (int) data_get($target->config, 'access.session_hours', 24);
        $cookie = $this->access->grant($request, $target->scope, $channel, $hours);
        $data   = $this->view->fullView($target, $this->brandingFor($target), TrackingView::VIEWER_VERIFIED, now()->addHours($hours)->toISOString());

        return $this->json($data, 200, $cookie);
    }

    /**
     * Who is looking: staff of the owning company, a signed-in customer of this delivery,
     * or a device verified for it; null for a visitor with only the tracking number.
     */
    protected function viewerFor(Request $request, TrackingTarget $target): ?string
    {
        $user = $this->requestUser($request);
        if ($user && data_get($user, 'type') !== 'customer' && data_get($user, 'company_uuid') === $target->companyUuid()) {
            return TrackingView::VIEWER_STAFF;
        }

        if ($user && $target->scope && in_array($target->scope->customer_uuid, $this->contactsFor($user), true)) {
            return TrackingView::VIEWER_ACCOUNT;
        }

        return $this->access->hasGrant($request, $target->scope) ? TrackingView::VIEWER_VERIFIED : null;
    }

    protected function resolve(Request $request, mixed $number): ?TrackingTarget
    {
        return $this->resolver->resolve($number, $this->slug($request));
    }

    protected function slug(Request $request): ?string
    {
        $slug = $request->input('org');

        return is_string($slug) && trim($slug) !== '' ? strtolower(trim($slug)) : null;
    }

    protected function brandingFor(TrackingTarget $target): array
    {
        return $this->view->branding($target->config, $this->findCompany($target->companyUuid()));
    }

    protected function signInOffered(array $config): bool
    {
        return (bool) data_get($config, 'access.account_sign_in', true) && $this->customerPortalInstalled();
    }

    protected function recipientFor(TrackingTarget $target): TrackingRecipient
    {
        return TrackingRecipient::for($target->scope, $this->fallbackPhone($target));
    }

    protected function fallbackPhone(TrackingTarget $target): ?string
    {
        return data_get($target->waypoint, 'place.phone') ?? data_get($target->order, 'payload.dropoff.phone');
    }

    protected function deliver(string $channel, string $destination, string $code, TrackingTarget $target): void
    {
        $name    = $this->brandingFor($target)['name'] ?? 'Your delivery';
        $message = $code . ' is your ' . $name . ' delivery code. It expires in ' . TrackingAccess::CODE_MINUTES . ' minutes.';

        if ($channel === TrackingRecipient::CHANNEL_SMS) {
            $this->sendSms($destination, $message, $target);

            return;
        }

        $this->sendEmail($destination, $code . ' is your ' . $name . ' delivery code', $message);
    }

    protected function saveInstructions(Order $order, TrackingScope $scope, string $text): void
    {
        $instructions                      = (array) $order->getMeta('recipient_instructions', []);
        $instructions[sha1($scope->key())] = $text;
        $order->setMeta('recipient_instructions', $instructions);
        $order->saveQuietly();
    }

    protected function dataUrl(string $raw): string
    {
        return str_starts_with($raw, 'data:') ? $raw : 'data:image/png;base64,' . $raw;
    }

    protected function notFound(): JsonResponse
    {
        return $this->json(['error' => self::NOT_FOUND, 'code' => 'not_found'], 404);
    }

    protected function json(array $data, int $status = 200, ?Cookie $cookie = null): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        if ($cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * Whether the instance can send SMS. Twilio is listed as always available, so it counts
     * only with credentials; other providers report their own configuration.
     */
    protected function smsAvailable(): bool
    {
        if (filled(config('services.twilio.sid')) && filled(config('services.twilio.token'))) {
            return true;
        }

        return collect($this->smsProviders())
            ->except(SmsService::PROVIDER_TWILIO)
            ->contains(fn ($provider) => (bool) data_get($provider, 'available'));
    }

    /**
     * The contact uuids a signed-in customer user acts as.
     *
     * @return array<int, string>
     */
    protected function contactsFor(mixed $user): array
    {
        if (!$user || data_get($user, 'type') !== 'customer' || !data_get($user, 'uuid')) {
            return [];
        }

        return $this->contactUuidsForUser((string) data_get($user, 'uuid'));
    }

    protected function contactUuidsForUser(string $userUuid): array
    {
        return Contact::withoutGlobalScopes()->where('user_uuid', $userUuid)->pluck('uuid')->all();
    }

    /**
     * Each order a customer is on, with the tracking number that opens their part of it.
     *
     * @param array<int, string> $contacts
     */
    protected function ordersForContacts(array $contacts): array
    {
        $payloads = Waypoint::withoutGlobalScopes()->whereIn('customer_uuid', $contacts)->pluck('payload_uuid')->filter()->unique()->values()->all();
        $orders   = Order::withoutGlobalScopes()
            ->with(['trackingNumber'])
            ->where(fn ($query) => $query->whereIn('customer_uuid', $contacts)->orWhereIn('payload_uuid', $payloads))
            ->latest()
            ->limit(50)
            ->get();

        return $orders->map(function (Order $order) use ($contacts) {
            $waypoint = in_array($order->customer_uuid, $contacts, true) ? null : Waypoint::withoutGlobalScopes()->with('trackingNumber')->where('payload_uuid', $order->payload_uuid)->whereIn('customer_uuid', $contacts)->first();
            $first    = Entity::withoutGlobalScopes()->where('payload_uuid', $order->payload_uuid)->value('name');

            return [
                'tracking_number' => $waypoint ? data_get($waypoint, 'trackingNumber.tracking_number') : data_get($order, 'trackingNumber.tracking_number'),
                'title'           => $first,
                'status'          => $order->status,
                'stage'           => $this->view->stage($order, null),
                'updated_at'      => $order->updated_at ? $order->updated_at->toISOString() : null,
            ];
        })->filter(fn ($row) => !empty($row['tracking_number']))->values()->all();
    }

    protected function findCompany(?string $companyUuid): mixed
    {
        return $companyUuid ? Company::where('uuid', $companyUuid)->first() : null;
    }

    protected function sendSms(string $phone, string $message, TrackingTarget $target): void
    {
        $company = $this->findCompany($target->companyUuid());
        $options = [];
        if (data_get($company, 'options.alpha_numeric_sender_id_enabled') && data_get($company, 'options.alpha_numeric_sender_id')) {
            $options['twilioParams'] = ['from' => data_get($company, 'options.alpha_numeric_sender_id')];
        }

        $this->smsService()->send($phone, $message, $options);
    }

    protected function sendEmail(string $email, string $subject, string $message): void
    {
        $this->mail($email, $subject, $message);
    }

    protected function sendReport(string $to, TrackingTarget $target, string $message): void
    {
        $number = $target->trackingNumber->tracking_number;

        $this->mail($to, 'Problem reported for ' . $number, 'A customer reported a problem with delivery ' . $number . ":\n\n" . $message);
    }

    protected function socketsEnabled(): bool
    {
        return TrackingPublisher::enabled();
    }

    // Environment seams: tests replace these.

    protected function socketTokenFor(TrackingScope $scope): array
    {
        // @codeCoverageIgnoreStart
        // Signs with the instance's socket auth key.
        return TrackingChannel::token($scope);
        // @codeCoverageIgnoreEnd
    }

    protected function mail(string $to, string $subject, string $body): void
    {
        // @codeCoverageIgnoreStart
        Mail::raw($body, fn ($mail) => $mail->to($to)->subject($subject));
        // @codeCoverageIgnoreEnd
    }

    protected function smsService(): SmsService
    {
        // @codeCoverageIgnoreStart
        return app(SmsService::class);
        // @codeCoverageIgnoreEnd
    }

    protected function smsProviders(): array
    {
        // @codeCoverageIgnoreStart
        return (new SmsService())->getAvailableProviders();
        // @codeCoverageIgnoreEnd
    }

    protected function requestUser(Request $request): mixed
    {
        // @codeCoverageIgnoreStart
        // A signed-in console or customer-portal user, from the bearer token the page sends.
        try {
            return auth('sanctum')->user();
        } catch (\Throwable) {
            return null;
        }
        // @codeCoverageIgnoreEnd
    }

    protected function customerPortalInstalled(): bool
    {
        // @codeCoverageIgnoreStart
        return collect(Utils::getInstalledFleetbaseExtensions())->contains(fn ($package) => data_get($package, 'name') === 'fleetbase/customer-portal-api');
        // @codeCoverageIgnoreEnd
    }

    protected function instanceLogo(): ?string
    {
        // @codeCoverageIgnoreStart
        return data_get(Setting::getBranding(), 'logo_url');
        // @codeCoverageIgnoreEnd
    }
}
