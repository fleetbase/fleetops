<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\TrackingSession;
use Fleetbase\FleetOps\Models\TrackingSessionGrant;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingAccess;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['app.key' => 'base64:tracking-test-key', 'fleetops.tracking.cookie_same_site' => 'lax', 'session.secure' => false, 'session.domain' => null]);
});

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::unsetConnectionResolver();
});

function trackingAccess(): TrackingAccess
{
    return new TrackingAccess(new RateLimiter(new Repository(new ArrayStore())));
}

function trackingScope(string $customer = 'contact-1'): TrackingScope
{
    return new TrackingScope('order-1', Contact::class, $customer);
}

function trackingRequest(array $cookies = [], string $ip = '203.0.113.7'): Request
{
    return Request::create('/public/track/NOR1', 'GET', [], $cookies, [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'PestBrowser']);
}

test('lookups, code checks and reports are limited per address', function () {
    $access  = trackingAccess();
    $request = trackingRequest();

    foreach (range(1, TrackingAccess::LOOKUPS_PER_IP) as $attempt) {
        expect($access->allowLookup($request))->toBeTrue();
    }

    expect($access->allowLookup($request))->toBeFalse()
        ->and($access->allowLookup(trackingRequest([], '198.51.100.1')))->toBeTrue();

    foreach (range(1, TrackingAccess::VERIFIES_PER_IP) as $attempt) {
        $access->allowVerify($request);
    }
    expect($access->allowVerify($request))->toBeFalse();

    foreach (range(1, TrackingAccess::REPORTS_PER_HOUR) as $attempt) {
        expect($access->allowReport($request, 'order-1'))->toBeTrue();
    }
    expect($access->allowReport($request, 'order-1'))->toBeFalse()
        ->and($access->allowReport($request, 'order-2'))->toBeTrue();
});

test('sending codes is limited by cooldown, per scope and per address', function () {
    $access  = trackingAccess();
    $request = trackingRequest();

    expect($access->claimSend(trackingScope(), $request))->toBe(['status' => TrackingAccess::SEND_SENT, 'retry_after' => TrackingAccess::RESEND_SECONDS])
        ->and($access->claimSend(trackingScope(), $request)['status'])->toBe(TrackingAccess::SEND_COOLDOWN);

    // Three sends per scope in the window, even after each cooldown.
    $limiter = new RateLimiter(new Repository(new ArrayStore()));
    $access  = new TrackingAccess($limiter);
    foreach (range(1, TrackingAccess::SENDS_PER_SCOPE) as $send) {
        $limiter->clear('trk-resend:' . trackingScope()->key());
        expect($access->claimSend(trackingScope(), trackingRequest([], '198.51.100.' . $send))['status'])->toBe(TrackingAccess::SEND_SENT);
    }
    $limiter->clear('trk-resend:' . trackingScope()->key());
    expect($access->claimSend(trackingScope(), $request)['status'])->toBe(TrackingAccess::SEND_LIMITED);

    // Five sends per address across scopes.
    $access = trackingAccess();
    foreach (range(1, TrackingAccess::SENDS_PER_IP) as $send) {
        $access->claimSend(trackingScope('contact-' . $send), $request);
    }
    expect($access->claimSend(trackingScope('contact-9'), $request)['status'])->toBe(TrackingAccess::SEND_LIMITED);
});

test('codes are issued hashed, checked, and pause the scope after three wrong ones', function () {
    TrackingPageDatabase::boot();
    $access = trackingAccess();
    $scope  = trackingScope();

    $first  = $access->issueCode($scope, null, 'sms');
    $second = $access->issueCode($scope, null, 'email');

    expect(VerificationCode::withoutGlobalScopes()->count())->toBe(1)
        ->and($second->plainCode)->toMatch('/^\d{6}$/')
        ->and($second->code)->not->toBe($second->plainCode)
        ->and($second->getMeta('scope'))->toBe($scope->key())
        ->and($second->getMeta('channel'))->toBe('email')
        ->and($first->plainCode)->not->toBeNull();

    $wrong = $second->plainCode === '111111' ? '222222' : '111111';
    expect($access->checkCode($scope, $wrong))->toBe(['status' => VerificationCode::CHECK_INVALID, 'attempts_left' => 2])
        ->and($access->pausedFor($scope))->toBe(0);

    expect($access->checkCode($scope, $second->plainCode))->toBe(['status' => VerificationCode::CHECK_VALID, 'channel' => 'email'])
        ->and(VerificationCode::withoutGlobalScopes()->count())->toBe(0)
        ->and($access->checkCode($scope, $second->plainCode))->toBe(['status' => VerificationCode::CHECK_EXPIRED]);

    $third = $access->issueCode($scope, null, 'sms');
    $wrong = $third->plainCode === '111111' ? '222222' : '111111';
    $access->checkCode($scope, $wrong);
    $access->checkCode($scope, $wrong);
    expect($access->checkCode($scope, $wrong))->toBe(['status' => VerificationCode::CHECK_LOCKED, 'attempts_left' => 0])
        ->and($access->pausedFor($scope))->toBeGreaterThan(0)
        ->and($access->claimSend($scope, trackingRequest())['status'])->toBe(TrackingAccess::SEND_PAUSED);
});

test('a correct code gives the device a session and a grant for that customer only', function () {
    TrackingPageDatabase::boot();
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    $access = trackingAccess();
    $scope  = trackingScope();

    expect($access->session(trackingRequest()))->toBeNull()
        ->and($access->session(trackingRequest([TrackingAccess::COOKIE => 'short'])))->toBeNull()
        ->and($access->hasGrant(trackingRequest(), null))->toBeFalse();

    $cookie = $access->grant(trackingRequest(), $scope, 'sms', 24);
    $token  = $cookie->getValue();
    $device = trackingRequest([TrackingAccess::COOKIE => $token]);

    expect($cookie->getName())->toBe(TrackingAccess::COOKIE)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and(TrackingSession::query()->value('token_hash'))->toBe(hash('sha256', $token))
        ->and(TrackingSession::query()->first()->grants()->count())->toBe(1)
        ->and($access->hasGrant($device, $scope))->toBeTrue()
        ->and($access->hasGrant($device, trackingScope('contact-2')))->toBeFalse()
        ->and($access->grantExpiresAt($device, $scope))->toBe('2026-10-09T09:00:00.000000Z')
        ->and($access->grantExpiresAt(trackingRequest(), $scope))->toBeNull();

    // A second customer on the same device; a longer session extends the cookie, a shorter one doesn't.
    expect($access->grant($device, trackingScope('contact-2'), 'email', 12))->toBeNull()
        ->and($access->grant($device, trackingScope('contact-2'), 'email', 48)?->getValue())->toBe($token)
        ->and(TrackingSessionGrant::query()->count())->toBe(2);

    // Signing out of one delivery keeps the others.
    expect($access->revoke($device, $scope))->toBeNull()
        ->and($access->hasGrant($device, $scope))->toBeFalse()
        ->and($access->hasGrant($device, trackingScope('contact-2')))->toBeTrue();

    $expired = $access->revoke($device);
    expect($expired->getValue())->toBe('')
        ->and($expired->getExpiresTime())->toBe(1)
        ->and($access->session($device))->toBeNull()
        ->and($access->revoke($device)->getExpiresTime())->toBe(1);

    // Expired sessions don't count.
    $fresh = $access->grant(trackingRequest(), $scope, 'sms', 1);
    Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00'));
    expect($access->session(trackingRequest([TrackingAccess::COOKIE => $fresh->getValue()])))->toBeNull();
});

test('the session cookie follows the same-site setting', function () {
    $access = trackingAccess();

    config(['fleetops.tracking.cookie_same_site' => 'none']);
    $none = $access->cookie('value', 60);
    expect($none->getSameSite())->toBe('none')->and($none->isSecure())->toBeTrue();

    config(['fleetops.tracking.cookie_same_site' => 'bogus', 'session.secure' => true]);
    $fallback = $access->cookie('value', 60);
    expect($fallback->getSameSite())->toBe('lax')->and($fallback->isSecure())->toBeTrue();
});
