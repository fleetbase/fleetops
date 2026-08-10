<?php

namespace Fleetbase\FleetOps\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Accepts a fixed verification code, but only for explicitly designated accounts.
 *
 * App store reviewers cannot receive our SMS or email, so a fixed code has to keep
 * working for them — including in production, which is where a review build is
 * tested. Refusing the bypass outside production made review impossible; refusing
 * it for everyone except named accounts keeps review working while removing the
 * real hazard, which was that anyone holding the code could authenticate as any
 * driver or customer.
 */
trait ResolvesReviewAccountBypass
{
    /**
     * @param string      $codeKey     config key holding the bypass code
     * @param string      $accountsKey config key holding the allowlisted identities
     * @param string|null $identity    identity being authenticated, already normalised
     * @param string|null $code        submitted verification code
     * @param string      $channel     label for the audit log entry
     */
    protected static function reviewAccountBypassMatches(
        string $codeKey,
        string $accountsKey,
        ?string $identity,
        ?string $code,
        string $channel,
    ): bool {
        $bypassCode = config($codeKey);

        // `!== null && !== ''` rather than `!empty()`: `!empty('0')` is false, so a
        // configured code of "0" would otherwise be silently ignored.
        if ($bypassCode === null || $bypassCode === '' || $code === null || $code === '' || $identity === null || $identity === '') {
            return false;
        }

        $accounts = array_map(
            static fn ($account) => strtolower(trim((string) $account)),
            (array) config($accountsKey, [])
        );

        if (!in_array(strtolower(trim($identity)), $accounts, true)) {
            return false;
        }

        // Constant-time, so a wrong code cannot be recovered by timing the response.
        if (!hash_equals((string) $bypassCode, (string) $code)) {
            return false;
        }

        Log::warning('[Fleetbase] Verification bypass accepted for a review account.', [
            'channel'  => $channel,
            'identity' => $identity,
        ]);

        return true;
    }
}
