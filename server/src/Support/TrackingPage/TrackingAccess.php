<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Fleetbase\FleetOps\Models\TrackingSession;
use Fleetbase\FleetOps\Models\TrackingSessionGrant;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Scopes\ExpiryScope;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * One-time codes and device sessions for the public tracking page.
 *
 * A code is sent to a scope's recipient and proves the visitor is that customer. A correct
 * code gives the device a grant for that one customer within that one order. Codes are
 * stored hashed (VerificationCode::issue()), three wrong codes pause the scope for every
 * device, and sends are limited per scope and per address.
 */
class TrackingAccess
{
    public const COOKIE           = 'fb_track';
    public const CODE_PURPOSE     = 'fleetops_tracking_access';
    public const CODE_MINUTES     = 10;
    public const MAX_ATTEMPTS     = 3;
    public const PAUSE_SECONDS    = 300;
    public const RESEND_SECONDS   = 30;
    public const SENDS_PER_SCOPE  = 3;
    public const SENDS_PER_IP     = 5;
    public const SEND_WINDOW      = 900;
    public const LOOKUPS_PER_IP   = 30;
    public const LOOKUP_WINDOW    = 60;
    public const VERIFIES_PER_IP  = 10;
    public const REPORTS_PER_HOUR = 3;

    public const SEND_SENT     = 'sent';
    public const SEND_COOLDOWN = 'cooldown';
    public const SEND_LIMITED  = 'limited';
    public const SEND_PAUSED   = 'paused';

    public function __construct(protected ?RateLimiter $limiter = null)
    {
    }

    /**
     * Whether this address may make another lookup, counting it.
     */
    public function allowLookup(Request $request): bool
    {
        return $this->attempt('trk-lookup:' . $request->ip(), self::LOOKUPS_PER_IP, self::LOOKUP_WINDOW);
    }

    /**
     * Whether this address may check another code, counting it.
     */
    public function allowVerify(Request $request): bool
    {
        return $this->attempt('trk-verify:' . $request->ip(), self::VERIFIES_PER_IP, self::LOOKUP_WINDOW);
    }

    /**
     * Whether this address may report another problem with this order, counting it.
     */
    public function allowReport(Request $request, string $orderUuid): bool
    {
        return $this->attempt('trk-report:' . $orderUuid . ':' . $request->ip(), self::REPORTS_PER_HOUR, 3600);
    }

    /**
     * Whether a scope's codes are paused after too many wrong ones, and for how long.
     */
    public function pausedFor(TrackingScope $scope): int
    {
        $key = $this->pauseKey($scope);

        return $this->limiter()->tooManyAttempts($key, 1) ? max(1, $this->limiter()->availableIn($key)) : 0;
    }

    /**
     * Check the limits for sending a code and, when they allow it, count the send.
     *
     * @return array{status: string, retry_after: int}
     */
    public function claimSend(TrackingScope $scope, Request $request): array
    {
        if ($paused = $this->pausedFor($scope)) {
            return ['status' => self::SEND_PAUSED, 'retry_after' => $paused];
        }

        $cooldown = 'trk-resend:' . $scope->key();
        if ($this->limiter()->tooManyAttempts($cooldown, 1)) {
            return ['status' => self::SEND_COOLDOWN, 'retry_after' => max(1, $this->limiter()->availableIn($cooldown))];
        }

        foreach (['trk-send-scope:' . $scope->key() => self::SENDS_PER_SCOPE, 'trk-send-ip:' . $request->ip() => self::SENDS_PER_IP] as $key => $max) {
            if ($this->limiter()->tooManyAttempts($key, $max)) {
                return ['status' => self::SEND_LIMITED, 'retry_after' => max(1, $this->limiter()->availableIn($key))];
            }
        }

        $this->limiter()->hit($cooldown, self::RESEND_SECONDS);
        $this->limiter()->hit('trk-send-scope:' . $scope->key(), self::SEND_WINDOW);
        $this->limiter()->hit('trk-send-ip:' . $request->ip(), self::SEND_WINDOW);

        return ['status' => self::SEND_SENT, 'retry_after' => self::RESEND_SECONDS];
    }

    /**
     * A new code for a scope, voiding the scope's earlier unused ones. The plain code is on `plainCode`.
     */
    public function issueCode(TrackingScope $scope, mixed $recipient, string $channel): VerificationCode
    {
        $this->voidCodes($scope);

        return $this->createCode($recipient, [
            'expireAfter' => Carbon::now()->addMinutes(self::CODE_MINUTES),
            'meta'        => [
                'scope'        => $scope->key(),
                'channel'      => $channel,
                'company_uuid' => $scope->company_uuid,
            ],
        ]);
    }

    /**
     * Check a code for a scope.
     *
     * @return array{status: string, attempts_left?: int, channel?: string|null}
     */
    public function checkCode(TrackingScope $scope, string $code): array
    {
        $verificationCode = $this->latestCode($scope);
        if (!$verificationCode) {
            return ['status' => VerificationCode::CHECK_EXPIRED];
        }

        $result = $verificationCode->check($code, self::MAX_ATTEMPTS);
        if ($result === VerificationCode::CHECK_VALID) {
            $channel = $verificationCode->getMeta('channel');
            $this->deleteCode($verificationCode);

            return ['status' => $result, 'channel' => $channel];
        }

        if ($result === VerificationCode::CHECK_LOCKED) {
            $this->limiter()->hit($this->pauseKey($scope), self::PAUSE_SECONDS);
        }

        return ['status' => $result, 'attempts_left' => $verificationCode->attemptsLeft(self::MAX_ATTEMPTS)];
    }

    /**
     * The device session the request's cookie names, when it is live.
     */
    public function session(Request $request): ?TrackingSession
    {
        $token = $request->cookie(self::COOKIE);
        if (!is_string($token) || strlen($token) < 32) {
            return null;
        }

        $session = $this->findSession(hash('sha256', $token));
        if (!$session || $session->revoked_at || ($session->expires_at && $session->expires_at->isPast())) {
            return null;
        }

        return $session;
    }

    /**
     * Whether the request's device holds a live grant for this scope.
     */
    public function hasGrant(Request $request, ?TrackingScope $scope): bool
    {
        if (!$scope) {
            return false;
        }

        $session = $this->session($request);

        return $session !== null && $this->findGrant($session, $scope) !== null;
    }

    /**
     * When the device's grant for this scope ends.
     */
    public function grantExpiresAt(Request $request, TrackingScope $scope): ?string
    {
        $session = $this->session($request);
        $grant   = $session ? $this->findGrant($session, $scope) : null;

        return $grant?->expires_at?->toISOString();
    }

    /**
     * Give the device a grant for a scope, starting a session when it has none.
     *
     * @return Cookie|null the cookie to set when a new session was started
     */
    public function grant(Request $request, TrackingScope $scope, ?string $channel, int $hours): ?Cookie
    {
        $expiresAt = Carbon::now()->addHours($hours);
        $session   = $this->session($request);
        $cookie    = null;

        if (!$session) {
            $token   = Str::random(40);
            $session = $this->createSession([
                'token_hash'   => hash('sha256', $token),
                'ip'           => $request->ip(),
                'user_agent'   => Str::limit((string) $request->userAgent(), 250, ''),
                'last_seen_at' => Carbon::now(),
                'expires_at'   => $expiresAt,
            ]);
            $cookie = $this->cookie($token, $hours * 60);
        } elseif (!$session->expires_at || $session->expires_at->lt($expiresAt)) {
            $this->saveSession($session, ['expires_at' => $expiresAt, 'last_seen_at' => Carbon::now()]);
            $cookie = $this->cookie((string) $request->cookie(self::COOKIE), $hours * 60);
        }

        $this->saveGrant($session, $scope, $channel, $expiresAt);

        return $cookie;
    }

    /**
     * Sign the device out: of one scope, or of every scope when none is given.
     *
     * @return Cookie|null an expired cookie when the whole session ended
     */
    public function revoke(Request $request, ?TrackingScope $scope = null): ?Cookie
    {
        $session = $this->session($request);
        if (!$session) {
            return $this->cookie('', -1);
        }

        if ($scope) {
            $this->deleteGrant($session, $scope);

            return null;
        }

        $this->saveSession($session, ['revoked_at' => Carbon::now()]);

        return $this->cookie('', -1);
    }

    public function cookie(string $value, int $minutes): Cookie
    {
        $sameSite = strtolower((string) config('fleetops.tracking.cookie_same_site', 'lax'));
        $sameSite = in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax';
        $secure   = $sameSite === 'none' || (bool) config('session.secure', false);

        return Cookie::create(self::COOKIE, $value, $minutes < 0 ? 1 : Carbon::now()->addMinutes($minutes)->getTimestamp(), '/', config('session.domain'), $secure, true, false, $sameSite);
    }

    protected function attempt(string $key, int $max, int $decay): bool
    {
        if ($this->limiter()->tooManyAttempts($key, $max)) {
            return false;
        }

        $this->limiter()->hit($key, $decay);

        return true;
    }

    protected function pauseKey(TrackingScope $scope): string
    {
        return 'trk-pause:' . $scope->key();
    }

    protected function limiter(): RateLimiter
    {
        return $this->limiter ??= app(RateLimiter::class);
    }

    protected function voidCodes(TrackingScope $scope): void
    {
        VerificationCode::withoutGlobalScope(ExpiryScope::class)->where('for', self::CODE_PURPOSE)->where('meta->scope', $scope->key())->delete();
    }

    protected function createCode(mixed $recipient, array $options): VerificationCode
    {
        return VerificationCode::issue($recipient, self::CODE_PURPOSE, $options);
    }

    protected function latestCode(TrackingScope $scope): ?VerificationCode
    {
        return VerificationCode::withoutGlobalScope(ExpiryScope::class)->where('for', self::CODE_PURPOSE)->where('meta->scope', $scope->key())->latest()->first();
    }

    protected function deleteCode(VerificationCode $verificationCode): void
    {
        $verificationCode->delete();
    }

    protected function findSession(string $tokenHash): ?TrackingSession
    {
        return TrackingSession::where('token_hash', $tokenHash)->first();
    }

    protected function createSession(array $attributes): TrackingSession
    {
        return TrackingSession::create($attributes);
    }

    protected function saveSession(TrackingSession $session, array $attributes): void
    {
        $session->forceFill($attributes)->save();
    }

    protected function findGrant(TrackingSession $session, TrackingScope $scope): ?TrackingSessionGrant
    {
        return TrackingSessionGrant::where('tracking_session_uuid', $session->uuid)
            ->where('order_uuid', $scope->order_uuid)
            ->where('customer_type', $scope->customer_type)
            ->where('customer_uuid', $scope->customer_uuid)
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    protected function saveGrant(TrackingSession $session, TrackingScope $scope, ?string $channel, Carbon $expiresAt): void
    {
        TrackingSessionGrant::updateOrCreate(
            ['tracking_session_uuid' => $session->uuid, 'order_uuid' => $scope->order_uuid, 'customer_type' => $scope->customer_type, 'customer_uuid' => $scope->customer_uuid],
            ['company_uuid' => $scope->company_uuid, 'channel' => $channel, 'expires_at' => $expiresAt]
        );
    }

    protected function deleteGrant(TrackingSession $session, TrackingScope $scope): void
    {
        TrackingSessionGrant::where('tracking_session_uuid', $session->uuid)
            ->where('order_uuid', $scope->order_uuid)
            ->where('customer_type', $scope->customer_type)
            ->where('customer_uuid', $scope->customer_uuid)
            ->delete();
    }
}
