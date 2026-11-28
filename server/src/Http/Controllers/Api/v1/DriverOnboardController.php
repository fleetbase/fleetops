<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\FleetOps\Exceptions\ProfileIdentityConflictException;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Support\DriverOnboarding;
use Fleetbase\FleetOps\Support\ProfileAccountManager;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A new driver signing themselves up from the Navigator app.
 *
 * Three calls, all made before there is a driver and so open to the instance's
 * platform token: look a join code up, ask for a verification code on a phone
 * number, then create the account with that code. Each one checks again that the
 * organization named by the join code still lets drivers sign themselves up.
 */
class DriverOnboardController extends Controller
{
    public const VERIFICATION_PURPOSE = 'fleetops_create_driver';
    public const CODE_CHECK_ATTEMPTS  = 5;

    /**
     * The organization a join code belongs to and what it asks of a new driver.
     */
    public function join(string $code)
    {
        $company = $this->signUpCompany($code);
        if (!$company) {
            return response()->apiError('That join code is not in use. Check it with your organization.', 404);
        }

        return response()->json(['onboarding' => DriverOnboarding::summary($company)]);
    }

    /**
     * Texts a verification code to the phone number a new driver is signing up with.
     */
    public function requestCode(Request $request)
    {
        $company = $this->signUpCompany($request->input('join_code'));
        if (!$company) {
            return response()->apiError('That join code is not in use. Check it with your organization.', 404);
        }

        $phone = static::phone($request->input('phone'));
        if (!static::looksLikePhone($phone)) {
            return response()->apiError('Enter a phone number with its country code.', 422);
        }

        if ($this->driverExists($company, $phone)) {
            return response()->apiError('This number already has a driver account with ' . $company->name . '. Sign in instead.', 409);
        }

        // The code's subject is the person signing up: their existing account as it
        // stands, else an unsaved one carrying what the message needs. Nothing is
        // stored until the code is verified.
        $subject = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        if (!$subject) {
            $subject = new User();
            $subject->forceFill([
                'company_uuid' => $company->uuid,
                'name'         => trim((string) $request->input('name', '')) ?: $phone,
                'phone'        => $phone,
            ]);
        }

        try {
            VerificationCode::generateSmsVerificationFor($subject, static::VERIFICATION_PURPOSE, [
                'hashed'          => true,
                'company_uuid'    => $company->uuid,
                'messageCallback' => fn ($verification) => 'Your ' . $company->name . ' verification code is ' . $verification->code,
                'meta'            => ['identity' => $phone, 'company' => $company->uuid],
            ]);
        } catch (SmsBlockedException $e) {
            $response = response()->apiError($e->getMessage(), $e->status());
            if ($response instanceof Response) {
                $response->headers->add($e->headers());
            }

            return $response;
        } catch (\Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            return response()->apiError(app()->hasDebugModeEnabled() ? $e->getMessage() : 'The verification code could not be sent.');
        }

        return response()->json(['status' => 'ok', 'method' => 'sms']);
    }

    /**
     * Creates the driver once the code sent to their phone checks out, and signs them in.
     */
    public function create(Request $request)
    {
        $company = $this->signUpCompany($request->input('join_code'));
        if (!$company) {
            return response()->apiError('That join code is not in use. Check it with your organization.', 404);
        }

        $phone = static::phone($request->input('phone'));
        $name  = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', '')) ?: null;

        if ($name === '') {
            return response()->apiError('Enter your name.', 422);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->apiError('That email address does not look right.', 422);
        }

        // The newest live code sent to this number for this organization.
        $verification = VerificationCode::findActiveFor(static::VERIFICATION_PURPOSE, $phone);
        if (!$verification || data_get($verification, 'meta.company') !== $company->uuid || $verification->check((string) $request->input('code'), static::CODE_CHECK_ATTEMPTS) !== VerificationCode::CHECK_VALID) {
            return response()->apiError('That code is not right, or it has expired.', 422);
        }
        $verification->consume();

        if ($this->driverExists($company, $phone)) {
            return response()->apiError('This number already has a driver account with ' . $company->name . '. Sign in instead.', 409);
        }

        try {
            $driver = DB::transaction(function () use ($company, $name, $email, $phone) {
                $user = ProfileAccountManager::resolveForProfile($company->uuid, 'driver', $name, $email, $phone, ['status' => 'active']);

                return Driver::create([
                    'user_uuid'    => $user->uuid,
                    'company_uuid' => $company->uuid,
                    // Held back from work until the organization has seen the documents it asks for.
                    'status'       => count(DriverOnboarding::documents(DriverOnboarding::settings($company->uuid))) > 0 ? 'pending' : 'available',
                    'online'       => 0,
                    'meta'         => ['onboarded_from' => 'navigator', 'onboarded_at' => now()->toISOString()],
                ]);
            });
        } catch (ProfileIdentityConflictException $e) {
            return response()->apiError($e->getMessage(), 422);
        }

        $user = User::where('uuid', $driver->user_uuid)->first();
        if (!$user) {
            return response()->apiError('Unable to authenticate driver.');
        }

        $token = $user->createToken($driver->uuid);
        DB::table('drivers')->where('uuid', $driver->uuid)->update(['auth_token' => $token->plainTextToken]);
        $driver->token = $token->plainTextToken;

        return new DriverResource($driver->load(['user', 'vehicle', 'vendor', 'currentJob']));
    }

    /**
     * The organization a join code names, only while it lets drivers sign themselves up.
     */
    protected function signUpCompany(mixed $code): ?Company
    {
        $company = DriverOnboarding::companyForCode(is_string($code) ? $code : null);
        if (!$company || !DriverOnboarding::allowsSelfSignUp(DriverOnboarding::settings($company->uuid))) {
            return null;
        }

        return $company;
    }

    protected function driverExists(Company $company, string $phone): bool
    {
        return Driver::where('company_uuid', $company->uuid)
            ->whereHas('user', fn ($query) => $query->where('phone', $phone))
            ->exists();
    }

    protected static function phone(mixed $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', trim((string) $phone));
        if ($phone === '') {
            return '';
        }

        return Str::startsWith($phone, '+') ? $phone : '+' . $phone;
    }

    protected static function looksLikePhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }
}
